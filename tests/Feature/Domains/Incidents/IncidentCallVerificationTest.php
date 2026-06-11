<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Listeners\StartCallVerificationOnIncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Roadmap V2-A3: every panic incident triggers the operator voice
 * verification chain when the tenant opted in.
 */
class IncidentCallVerificationTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_start_is_idempotent_for_the_same_incident(): void
    {
        $this->enableVerification();

        $incident = $this->makePanicIncident();

        $this->handleCreated($incident);
        $this->handleCreated($incident);

        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->count());
        Queue::assertPushed(PlaceVerificationCallJob::class, 1);
    }

    public function test_a_concluded_verification_is_never_restarted(): void
    {
        $this->enableVerification();

        $incident = $this->makePanicIncident();

        IncidentCallVerification::factory()->create([
            'team_id' => $this->teamId,
            'incident_id' => $incident->id,
            'status' => CallVerificationStatus::Answered,
            'outcome' => CallVerificationOutcome::ConfirmedFalse,
        ]);

        $this->handleCreated($incident);

        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->count());
        Queue::assertNotPushed(PlaceVerificationCallJob::class);
    }

    public function test_without_any_phone_contact_no_call_is_started(): void
    {
        $this->setSetting(StartIncidentCallVerification::SETTING_ENABLED, true);

        $this->handleCreated($this->makePanicIncident());

        $this->assertSame(0, IncidentCallVerification::withoutGlobalScopes()->count());
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
