<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Listeners\StartCallVerificationOnIncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Roadmap V2-A3: every panic incident triggers the operator voice
 * verification chain when the tenant opted in.
 */
class IncidentCallVerificationTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    private function setSetting(string $key, mixed $value, SettingValueType $type = SettingValueType::Boolean): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->teamId,
            'setting_key' => $key,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => $value],
            'value_type' => $type,
        ]);
    }

    private function enableVerification(array $contacts = ['+5215512345678']): void
    {
        $this->setSetting(StartIncidentCallVerification::SETTING_ENABLED, true);
        $this->setSetting(StartIncidentCallVerification::SETTING_CONTACTS, $contacts, SettingValueType::Json);
    }

    private function makePanicIncident(): Incident
    {
        $type = IncidentType::factory()->panic()->create();

        return Incident::factory()->open()->create([
            'team_id' => $this->teamId,
            'incident_type_id' => $type->id,
        ]);
    }

    private function handleCreated(Incident $incident): void
    {
        app(StartCallVerificationOnIncidentCreated::class)->handle(new IncidentCreated($incident));
    }

    public function test_panic_incident_starts_a_verification_call_when_opted_in(): void
    {
        $this->enableVerification();

        $incident = $this->makePanicIncident();

        $this->handleCreated($incident);

        $verification = IncidentCallVerification::withoutGlobalScopes()
            ->where('incident_id', $incident->id)
            ->sole();

        $this->assertSame($this->teamId, $verification->team_id);
        $this->assertSame('+5215512345678', $verification->phone);
        $this->assertSame(1, $verification->attempt);
        $this->assertSame(CallVerificationStatus::Pending, $verification->status);

        Queue::assertPushed(
            PlaceVerificationCallJob::class,
            fn (PlaceVerificationCallJob $job) => $job->verificationId === $verification->id,
        );

        $this->assertSystemLogged('incidents.call_verification.requested', fn (array $c) => $c['input'] === ['incident_id' => $incident->id, 'attempt' => 1]
            && $c['calc']['candidates_from'] === 'resolved'
            && $c['calc']['candidates_count'] === 1
            && $c['calc']['candidate_index'] === 0
            && $c['calc']['candidate_counts_by_source']['verification_contacts'] === 1
            && $c['calc']['restarted_after_suppression'] === false
            && $c['result'] === ['verification_id' => $verification->id, 'job_requested' => true]);
        $this->assertStringNotContainsString('+5215512345678', json_encode($this->systemLogEntries()));
        $this->assertStringNotContainsString('5215512345678', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_does_nothing_by_default_because_verification_is_opt_in(): void
    {
        $this->handleCreated($this->makePanicIncident());

        $this->assertSame(0, IncidentCallVerification::withoutGlobalScopes()->count());
        Queue::assertNotPushed(PlaceVerificationCallJob::class);
    }

    public function test_non_panic_incidents_never_trigger_the_call(): void
    {
        $this->enableVerification();

        $type = IncidentType::factory()->geofenceBreach()->create();
        $incident = Incident::factory()->open()->create([
            'team_id' => $this->teamId,
            'incident_type_id' => $type->id,
        ]);

        $this->handleCreated($incident);

        $this->assertSame(0, IncidentCallVerification::withoutGlobalScopes()->count());

        $entry = collect($this->systemLogEntries('incidents.call_verification.skipped'))->sole();
        $this->assertSame('debug', $entry['level']);
        $this->assertSame('not_panic', $entry['context']['reason']);
        $this->assertSame(['incident_id' => $incident->id, 'incident_type_code' => 'geofence_breach'], $entry['context']['input']);
        $this->assertSystemNotLogged('incidents.call_verification.requested');
    }

    public function test_verification_disabled_by_the_tenant_is_logged_and_never_calls(): void
    {
        $this->setSetting(StartIncidentCallVerification::SETTING_ENABLED, false);
        $this->setSetting(StartIncidentCallVerification::SETTING_CONTACTS, ['+5215512345678'], SettingValueType::Json);

        $incident = $this->makePanicIncident();

        $this->handleCreated($incident);

        $this->assertSame(0, IncidentCallVerification::withoutGlobalScopes()->count());
        Queue::assertNotPushed(PlaceVerificationCallJob::class);

        $this->assertSystemLogged('incidents.call_verification.skipped', fn (array $c) => $c['reason'] === 'disabled_by_tenant'
            && $c['input']['incident_id'] === $incident->id
            && $c['calc'] === ['setting_key' => StartIncidentCallVerification::SETTING_ENABLED]);
        $this->assertSystemNotLogged('incidents.call_verification.requested');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_start_is_idempotent_for_the_same_incident(): void
    {
        $this->enableVerification();

        $incident = $this->makePanicIncident();

        $this->handleCreated($incident);
        $this->handleCreated($incident);

        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->count());
        Queue::assertPushed(PlaceVerificationCallJob::class, 1);

        $existing = IncidentCallVerification::withoutGlobalScopes()->sole();
        $this->assertCount(1, $this->systemLogEntries('incidents.call_verification.requested'));
        $this->assertSystemLogged('incidents.call_verification.skipped', fn (array $c) => $c['reason'] === 'already_in_flight'
            && $c['result'] === ['verification_id' => $existing->id]);
    }

    public function test_a_concluded_verification_is_never_restarted(): void
    {
        $this->enableVerification();

        $incident = $this->makePanicIncident();

        $concluded = IncidentCallVerification::factory()->create([
            'team_id' => $this->teamId,
            'incident_id' => $incident->id,
            'status' => CallVerificationStatus::Answered,
            'outcome' => CallVerificationOutcome::ConfirmedFalse,
        ]);

        $this->handleCreated($incident);

        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->count());
        Queue::assertNotPushed(PlaceVerificationCallJob::class);

        $this->assertSystemLogged('incidents.call_verification.skipped', fn (array $c) => $c['reason'] === 'already_concluded'
            && $c['result'] === ['verification_id' => $concluded->id, 'status' => CallVerificationStatus::Answered->value]);
        $this->assertSystemNotLogged('incidents.call_verification.requested');
    }

    public function test_without_any_phone_contact_no_call_is_started(): void
    {
        $this->setSetting(StartIncidentCallVerification::SETTING_ENABLED, true);

        $incident = $this->makePanicIncident();

        $this->handleCreated($incident);

        $this->assertSame(0, IncidentCallVerification::withoutGlobalScopes()->count());

        $this->assertSystemLogged('incidents.call_verification.skipped', fn (array $c) => $c['reason'] === 'no_phone_contact'
            && $c['input']['incident_id'] === $incident->id
            && $c['calc']['candidate_counts_by_source'] === ['driver' => 0, 'verification_contacts' => 0, 'escalation_steps' => 0, 'supervisors' => 0]);
        $this->assertSystemLogged('incidents.call_verification.unverifiable_escalated', fn (array $c) => $c['input'] === ['incident_id' => $incident->id, 'unverifiable_code' => StartIncidentCallVerification::NO_PHONE_REASON]
            && $c['result']['escalated_now'] === true
            && $c['result']['status_after'] === IncidentStatusCode::Escalated->value);
        $this->assertSystemNotLogged('incidents.call_verification.requested');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_second_unverifiable_escalation_is_recorded_once(): void
    {
        $this->setSetting(StartIncidentCallVerification::SETTING_ENABLED, true);

        $incident = $this->makePanicIncident();

        $this->handleCreated($incident);
        $this->handleCreated($incident->fresh());

        $this->assertSystemLogged('incidents.call_verification.unverifiable_escalated', fn (array $c) => ($c['reason'] ?? null) === 'already_recorded'
            && $c['input']['unverifiable_code'] === StartIncidentCallVerification::NO_PHONE_REASON);
    }

    public function test_a_rolled_back_incident_never_logs_the_verification_request(): void
    {
        $this->seed(IncidentsSeeder::class);
        $this->enableVerification();

        Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'));

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        try {
            app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => 'panic_emergency', 'priority_code' => 'critical']);
            $this->fail('La creación debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
        $this->assertSame(0, IncidentCallVerification::withoutGlobalScopes()->count());
        $this->assertSystemNotLogged('incidents.call_verification.requested');
        $this->assertSystemLogged('incidents.type.resolved');
    }

    public function test_a_terminal_incident_never_starts_a_chain(): void
    {
        $this->enableVerification();

        $incident = Incident::factory()->closed()->create([
            'team_id' => $this->teamId,
            'incident_type_id' => IncidentType::factory()->panic()->create()->id,
        ]);

        $this->assertNull(app(StartIncidentCallVerification::class)->execute($incident, 2));

        $this->assertSystemLogged('incidents.call_verification.skipped', fn (array $c) => $c['reason'] === 'incident_terminal'
            && $c['input'] === ['incident_id' => $incident->id, 'attempt' => 2]);
        $this->assertSystemNotLogged('incidents.call_verification.requested');
    }

    public function test_phone_falls_back_to_escalation_step_contacts(): void
    {
        $this->setSetting(StartIncidentCallVerification::SETTING_ENABLED, true);

        TenantEscalationConfig::factory()->create([
            'team_id' => $this->teamId,
            'is_active' => true,
            'steps_json' => [
                ['delay_minutes' => 0, 'contacts' => ['oncall@example.com', '+5215587654321']],
            ],
        ]);

        $this->handleCreated($this->makePanicIncident());

        $verification = IncidentCallVerification::withoutGlobalScopes()->sole();
        $this->assertSame('+5215587654321', $verification->phone);
    }

    public function test_setting_of_another_tenant_does_not_leak(): void
    {
        $otherTeamId = User::factory()->create()->currentTeam->id;

        TenantSetting::factory()->create([
            'team_id' => $otherTeamId,
            'setting_key' => StartIncidentCallVerification::SETTING_ENABLED,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => true],
            'value_type' => SettingValueType::Boolean,
        ]);

        $this->handleCreated($this->makePanicIncident());

        $this->assertSame(0, IncidentCallVerification::withoutGlobalScopes()->count());
    }
}
