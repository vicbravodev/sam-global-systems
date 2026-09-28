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
    use AssertsTenantIsolation, RefreshDatabase;

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
