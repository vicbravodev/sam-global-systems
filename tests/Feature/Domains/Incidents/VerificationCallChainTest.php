<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: los operadores (choferes) no tienen cuenta, sólo
 * teléfono. La llamada de verificación va primero al chofer y después a los
 * contactos de la empresa; si no hay a quién llamar, NO es silencio: queda en
 * la línea de tiempo y se escala.
 */
class VerificationCallChainTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(IncidentStatusSeeder::class);
        $this->team = User::factory()->create(['phone' => null])->currentTeam;
    }

    private function contacts(array $phones): void
    {
        TenantSetting::factory()->create([
            'team_id' => $this->team->id,
            'setting_key' => StartIncidentCallVerification::SETTING_CONTACTS,
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => $phones],
            'value_type' => SettingValueType::Json,
        ]);
    }

    private function panicIncident(?Driver $driver = null): Incident
    {
        return Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'incident_type_id' => IncidentType::factory()->panic()->create()->id,
            'driver_id' => $driver?->id,
        ]);
    }

    public function test_the_driver_is_called_first_and_the_company_contact_next(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->team->id, 'phone' => '+5215500000001']);
        $this->contacts(['+5215500000002']);
        $incident = $this->panicIncident($driver);

        $first = app(StartIncidentCallVerification::class)->execute($incident);
        $this->assertSame('+5215500000001', $first->phone);

        app(HandleVerificationCallAttemptFailure::class)->execute($first, 'no-answer');

        $second = IncidentCallVerification::withoutGlobalScopes()->where('incident_id', $incident->id)->where('attempt', 2)->sole();
        $this->assertSame('+5215500000002', $second->phone);
        $this->assertSame(['+5215500000001', '+5215500000002'], $second->metadata_json['candidates']);

        $this->assertSystemLogged('incidents.call_verification.requested', fn (array $c) => $c['input']['attempt'] === 1
            && $c['calc']['candidates_from'] === 'resolved'
            && $c['calc']['candidate_counts_by_source']['driver'] >= 1
            && $c['calc']['candidate_counts_by_source']['verification_contacts'] === 1);
        // El reintento recorre la lista guardada: no se re-resuelve.
        $this->assertSystemLogged('incidents.call_verification.requested', fn (array $c) => $c['input']['attempt'] === 2
            && $c['calc']['candidates_from'] === 'metadata'
            && $c['calc']['candidate_counts_by_source'] === null
            && $c['calc']['candidate_index'] === 1
            && $c['result']['verification_id'] === $second->id);
        $this->assertSystemLogged('incidents.call_verification.attempt_failed', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc']['failure_code'] === 'no-answer'
            && $c['result'] === ['next' => 'next_attempt', 'next_attempt' => 2, 'next_attempt_created' => true]);

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('5215500000001', $json);
        $this->assertStringNotContainsString('5215500000002', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_duplicate_retry_request_reuses_the_attempt_already_requested(): void
    {
        $this->contacts(['+5215500000021', '+5215500000022']);
        $incident = $this->panicIncident();

        $first = app(StartIncidentCallVerification::class)->execute($incident);
        app(HandleVerificationCallAttemptFailure::class)->execute($first, 'timeout_without_callback');
        $second = IncidentCallVerification::withoutGlobalScopes()->where('incident_id', $incident->id)->where('attempt', 2)->sole();

        // Un segundo aviso de fallo (status callback tras el safety net) pide el mismo intento.
        $again = app(StartIncidentCallVerification::class)->execute($incident, 2);

        $this->assertSame($second->id, $again->id);
        Queue::assertPushed(PlaceVerificationCallJob::class, 2);

        $entry = collect($this->systemLogEntries('incidents.call_verification.skipped'))
            ->firstWhere('context.reason', 'attempt_already_requested');
        $this->assertNotNull($entry);
        $this->assertSame('debug', $entry['level']);
        $this->assertSame(['incident_id' => $incident->id, 'attempt' => 2], $entry['context']['input']);
        $this->assertSame(['verification_id' => $second->id], $entry['context']['result']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_late_failure_notice_for_the_same_attempt_does_not_claim_a_new_attempt(): void
    {
        $this->contacts(['+5215500000031', '+5215500000032', '+5215500000033']);
        $incident = $this->panicIncident();

        $first = app(StartIncidentCallVerification::class)->execute($incident);
        // Copia leída antes del primer aviso: el safety net y el status
        // callback cargan el mismo intento todavía en vuelo.
        $stale = IncidentCallVerification::withoutGlobalScopes()->findOrFail($first->id);

        app(HandleVerificationCallAttemptFailure::class)->execute($first, 'timeout_without_callback');
        app(HandleVerificationCallAttemptFailure::class)->execute($stale, 'call_status:no-answer');

        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->where('incident_id', $incident->id)->where('attempt', 2)->count());
        Queue::assertPushed(PlaceVerificationCallJob::class, 2);

        $entries = array_column($this->systemLogEntries('incidents.call_verification.attempt_failed'), 'context');
        $this->assertCount(2, $entries);
        $this->assertSame(['next' => 'next_attempt', 'next_attempt' => 2, 'next_attempt_created' => true], $entries[0]['result']);
        // Se pidió el siguiente intento, pero ya existía: no se creó otro.
        $this->assertSame(['next' => 'next_attempt', 'next_attempt' => 2, 'next_attempt_created' => false], $entries[1]['result']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_driver_mobile_contact_is_used_when_the_driver_has_no_phone(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->team->id, 'phone' => null]);
        DriverContact::factory()->primary()->create(['driver_id' => $driver->id, 'value' => '+5215500000009']);

        $verification = app(StartIncidentCallVerification::class)->execute($this->panicIncident($driver));

        $this->assertSame('+5215500000009', $verification->phone);
    }

    public function test_every_contact_is_called_at_least_once_even_beyond_the_attempt_budget(): void
    {
        $this->contacts(['+5215500000011', '+5215500000012', '+5215500000013', '+5215500000014']);
        $incident = $this->panicIncident();

        $verification = app(StartIncidentCallVerification::class)->execute($incident);

        $this->assertSame(4, app(StartIncidentCallVerification::class)->attemptBudget($verification));

        // El intento 3 falla: el presupuesto (4) todavía deja un intento más.
        $third = IncidentCallVerification::factory()->calling()->create([
            'team_id' => $this->team->id,
            'incident_id' => $incident->id,
            'attempt' => 3,
            'metadata_json' => $verification->metadata_json,
        ]);

        app(HandleVerificationCallAttemptFailure::class)->execute($third, 'timeout_without_callback');

        $context = $this->assertSystemLogged('incidents.call_verification.attempt_failed', fn (array $c) => $c['input']['attempt'] === 3);
        $calc = $context['calc'];
        $this->assertSame('timeout_without_callback', $calc['failure_code']);
        $this->assertNull($calc['call_status']);
        $this->assertSame(StartIncidentCallVerification::MAX_ATTEMPTS, $calc['max_attempts_cap']);
        $this->assertSame(StartIncidentCallVerification::DEFAULT_ATTEMPTS, $calc['configured_attempts']);
        $this->assertSame(4, $calc['candidates_count']);
        $this->assertSame(min($calc['max_attempts_cap'], max($calc['configured_attempts'], $calc['candidates_count'])), $calc['budget']);
        $this->assertSame(4, $calc['budget']);
        $this->assertSame(['next' => 'next_attempt', 'next_attempt' => 4, 'next_attempt_created' => true], $context['result']);
        $this->assertSame(1, IncidentCallVerification::withoutGlobalScopes()->where('incident_id', $incident->id)->where('attempt', 4)->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_no_phone_at_all_escalates_and_is_written_to_the_timeline(): void
    {
        $incident = $this->panicIncident();

        $this->assertNull(app(StartIncidentCallVerification::class)->execute($incident));

        $fresh = Incident::withoutGlobalScopes()->with('status')->find($incident->id);
        $this->assertSame(IncidentStatusCode::Escalated->value, $fresh->status->code);

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::VerificationCall)
            ->sole();
        $this->assertSame(StartIncidentCallVerification::NO_PHONE_REASON, $entry->payload_json['unverifiable_reason']);

        $this->assertSame(1, Notification::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('notification_type', 'incident.verification_unavailable')
            ->count());
        Queue::assertNotPushed(PlaceVerificationCallJob::class);
    }

    public function test_an_admin_verified_phone_is_the_last_resort(): void
    {
        $admin = User::factory()->withVerifiedPhone('+5215500000077')->create();
        $this->team->members()->attach($admin, ['role' => 'admin']);

        $verification = app(StartIncidentCallVerification::class)->execute($this->panicIncident());

        $this->assertSame('+5215500000077', $verification->phone);
    }

    public function test_another_tenants_driver_is_never_called(): void
    {
        $other = Team::factory()->create();
        $foreignDriver = Driver::factory()->create(['team_id' => $other->id, 'phone' => '+5215500000099']);

        // Incidente del team apuntando (por error de datos) al chofer de otro team.
        $incident = $this->panicIncident();
        $incident->forceFill(['driver_id' => $foreignDriver->id])->save();
        $this->contacts(['+5215500000002']);

        $verification = $this->assertNoTenantLeak(
            $this->team,
            fn () => app(StartIncidentCallVerification::class)->execute($incident->fresh()),
        );

        $this->assertSame('+5215500000002', $verification->phone);
        $this->assertNotContains('+5215500000099', $verification->metadata_json['candidates']);
    }
}
