<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Actions\ProcessHosReadings;
use App\Domains\Drivers\Actions\SettleHosIncident;
use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentResolved;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Si el chofer corrige antes de que alguien tome el incidente, se cierra
 * solo; si ya alguien lo tomó, sólo se anota en su línea de tiempo.
 */
class SettleHosIncidentTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    private TenantIntegration $integration;

    private Driver $driver;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
        Event::fake([IncidentStatusChanged::class, IncidentResolved::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:30:00'));
        $this->integration = $this->hosIntegration();
        [$this->driver, $this->asset] = $this->hosDriver($this->integration);
    }

    private function openIncident(array $attributes = []): Incident
    {
        return Incident::factory()->open()->create(['team_id' => $this->integration->team_id, 'driver_id' => $this->driver->id, ...$attributes]);
    }

    private function escalatedEpisode(?Incident $incident, HosSituation $situation = HosSituation::BreakDue): HosEpisode
    {
        return HosEpisode::factory()->create([
            'team_id' => $this->driver->team_id, 'driver_id' => $this->driver->id, 'asset_id' => $this->asset->id,
            'situation' => $situation, 'opened_at' => now()->subMinutes(30), 'ladder_step' => 6,
            'escalated_at' => now()->subMinutes(10), 'incident_id' => $incident?->id,
        ]);
    }

    /** El chofer tomó su break: el reloj vuelve a 8 h. */
    private function correct(int $drive = 30000): void
    {
        app(ProcessHosReadings::class)->execute(
            $this->integration->team_id,
            HosMonitoringConfig::fromArray([], config('hos.defaults')),
            new HosEnrollment([[
                'reading' => new HosClockReading('58072405', '281', 'offDuty', 28800, $drive, 40000, 200000, 0),
                'driver' => $this->driver,
                'asset' => $this->asset,
            ]], []),
            now()->toImmutable(),
        );
    }

    private function correctionNoted(Incident $incident): bool
    {
        return $incident->timeline()
            ->where('entry_type', TimelineEntryType::ExternallyResolved)
            ->where('title', 'El chofer ya corrigió')
            ->exists();
    }

    public function test_a_correction_before_anyone_takes_it_resolves_the_incident(): void
    {
        $incident = $this->openIncident();
        $episode = $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertSame(HosEpisodeResolution::Corrected, $episode->fresh()->resolution);
        $this->assertSame(IncidentStatusCode::Resolved->value, $incident->fresh('status')->status->code);
        $this->assertTrue($this->correctionNoted($incident));
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('resolved', $settled['result']['outcome']);
        $this->assertSame($incident->id, $settled['calc']['incident_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_incident_someone_already_took_only_gets_a_timeline_entry(): void
    {
        $incident = $this->openIncident(['acknowledged_at' => now()->subMinutes(5)]);
        $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertTrue($this->correctionNoted($incident));
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('annotated', $settled['result']['outcome']);
        $this->assertTrue($settled['calc']['acknowledged']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_incident_someone_claimed_only_gets_a_timeline_entry(): void
    {
        $incident = $this->openIncident(['claimed_by_user_id' => User::factory()->create()->id]);
        $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertTrue($this->correctionNoted($incident));
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('annotated', $settled['result']['outcome']);
        $this->assertTrue($settled['calc']['claimed']);
        $this->assertFalse($settled['calc']['acknowledged']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_corrected_violation_never_closes_its_incident(): void
    {
        // Una infracción ya ocurrió: aunque nadie la haya tomado, el incidente queda abierto.
        $incident = $this->openIncident();
        $episode = $this->escalatedEpisode($incident, HosSituation::Violation);

        $this->correct();

        $this->assertSame(HosEpisodeResolution::Corrected, $episode->fresh()->resolution);
        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertTrue($this->correctionNoted($incident));
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('violation_kept_open', $settled['result']['outcome']);
        $this->assertSame('violation', $settled['calc']['situation']);
        Event::assertNotDispatched(IncidentResolved::class);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_violation_and_a_break_sharing_one_incident_corrected_together_keep_it_open(): void
    {
        // El hos_unattended del descanso se plegó al incidente de la infracción.
        $incident = $this->openIncident();
        $violation = $this->escalatedEpisode($incident, HosSituation::Violation);
        $break = $this->escalatedEpisode($incident);

        $this->correct();                                     // un solo sondeo cierra los dos

        $this->assertNotNull($violation->fresh()->resolved_at);
        $this->assertNotNull($break->fresh()->resolved_at);
        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertSame(2, $incident->timeline()->where('title', SettleHosIncident::TIMELINE_TITLE)->count());
        $outcomes = array_map(fn (array $entry) => $entry['context']['result']['outcome'] ?? null, $this->systemLogEntries('hos.incident.settled'));
        $this->assertSame(['violation_kept_open', 'violation_kept_open'], $outcomes);
        Event::assertNotDispatched(IncidentResolved::class);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_break_corrected_after_its_violation_resolved_never_closes_the_violation_incident(): void
    {
        $incident = $this->openIncident();
        // La infracción ya se corrigió en un sondeo anterior (sigue vinculada).
        $this->escalatedEpisode($incident, HosSituation::Violation)->forceFill([
            'resolved_at' => now()->subMinutes(5), 'resolution' => HosEpisodeResolution::Corrected,
        ])->save();
        $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertTrue($this->correctionNoted($incident));
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('violation_kept_open', $settled['result']['outcome']);
        $this->assertSame('break_due', $settled['calc']['situation']);
        $this->assertTrue($settled['calc']['violation_incident']);
        $this->assertFalse($settled['calc']['other_open_episodes']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_incident_carrying_a_hos_limit_exceeded_event_is_never_closed_by_a_correction(): void
    {
        // Sin episodio de infracción vinculado: basta el evento del incidente.
        $type = EventType::query()->where('code', 'hos_limit_exceeded')->first() ?? EventType::factory()->create(['code' => 'hos_limit_exceeded']);
        $event = NormalizedEvent::factory()->create(['team_id' => $this->integration->team_id, 'event_type_id' => $type->id]);
        $incident = $this->openIncident(['related_event_id' => $event->id]);
        $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertSame('violation_kept_open', $this->assertSystemLogged('hos.incident.settled')['result']['outcome']);

        // Igual si el evento sólo está ligado (plegado) y no es el principal.
        $folded = $this->openIncident();
        IncidentEventLink::factory()->create(['incident_id' => $folded->id, 'normalized_event_id' => $event->id, 'relation_type' => EventRelationType::SupportingEvent]);
        $episode = $this->escalatedEpisode($folded, HosSituation::DriveLimit);
        $episode->forceFill(['resolved_at' => now(), 'resolution' => HosEpisodeResolution::Corrected])->save();

        $outcome = TenantContext::for($this->integration->team_id, fn () => app(SettleHosIncident::class)->execute($episode->fresh()));

        $this->assertSame('violation_kept_open', $outcome);
        $this->assertFalse($folded->fresh('status')->isTerminal());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_another_tenants_violation_event_never_keeps_this_incident_open(): void
    {
        $other = $this->hosIntegration();
        $type = EventType::query()->where('code', 'hos_limit_exceeded')->first() ?? EventType::factory()->create(['code' => 'hos_limit_exceeded']);
        $foreignEvent = NormalizedEvent::factory()->create(['team_id' => $other->team_id, 'event_type_id' => $type->id]);
        $incident = $this->openIncident();
        // Un vínculo corrupto a un evento ajeno no cuenta.
        IncidentEventLink::factory()->create(['incident_id' => $incident->id, 'normalized_event_id' => $foreignEvent->id, 'relation_type' => EventRelationType::SupportingEvent]);
        $this->escalatedEpisode($incident);

        $this->assertNoTenantLeak($this->integration->team_id, fn () => $this->correct());

        $this->assertTrue($incident->fresh('status')->isTerminal());
        $this->assertSame('resolved', $this->assertSystemLogged('hos.incident.settled')['result']['outcome']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_escalated_episode_tied_to_another_incident_does_not_keep_this_one_open(): void
    {
        $incident = $this->openIncident();
        $episode = $this->escalatedEpisode($incident);
        // Otro episodio del mismo chofer escaló a OTRO incidente (fuera de la ventana de plegado).
        $elsewhere = $this->openIncident();
        $drive = $this->escalatedEpisode($elsewhere, HosSituation::DriveLimit);

        $this->correct(drive: 0);

        $this->assertNull($drive->fresh()->resolved_at);
        $this->assertSame(HosEpisodeResolution::Corrected, $episode->fresh()->resolution);
        $this->assertTrue($incident->fresh('status')->isTerminal());
        $this->assertFalse($elsewhere->fresh('status')->isTerminal());
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('resolved', $settled['result']['outcome']);
        $this->assertFalse($settled['calc']['other_open_episodes']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_settling_the_same_episode_twice_adds_nothing_new(): void
    {
        $incident = $this->openIncident(['acknowledged_at' => now()->subMinutes(5)]);
        $episode = $this->escalatedEpisode($incident);
        $this->correct();

        $outcome = TenantContext::for($this->integration->team_id, fn () => app(SettleHosIncident::class)->execute($episode->fresh()));

        $this->assertSame('already_settled', $outcome);
        $this->assertSame(1, $incident->timeline()->where('title', SettleHosIncident::TIMELINE_TITLE)->count());
        $this->assertFalse($incident->fresh('status')->isTerminal());
        $this->assertSame('already_settled', $this->assertSystemLogged('hos.incident.settled', fn (array $c) => ($c['reason'] ?? null) === 'already_settled')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_closed_incident_is_left_alone(): void
    {
        $incident = Incident::factory()->resolved()->create(['team_id' => $this->integration->team_id]);
        $this->escalatedEpisode($incident);

        $this->correct();

        $this->assertFalse($this->correctionNoted($incident));
        $this->assertSame('already_closed', $this->assertSystemLogged('hos.incident.settled')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_unknown_incident_is_logged_and_the_poll_goes_on(): void
    {
        $episode = $this->escalatedEpisode(null);

        $this->correct();

        $this->assertSame(HosEpisodeResolution::Corrected, $episode->fresh()->resolution);
        $this->assertSame('incident_not_found', $this->assertSystemLogged('hos.incident.settled')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_incident_shared_with_another_open_episode_stays_open(): void
    {
        $incident = $this->openIncident();
        $this->escalatedEpisode($incident);
        // Sin manejo disponible: el de manejo sigue abierto y comparte el incidente.
        $drive = $this->escalatedEpisode($incident, HosSituation::DriveLimit);

        $this->correct(drive: 0);

        $this->assertNull($drive->fresh()->resolved_at);
        $this->assertFalse($incident->fresh('status')->isTerminal());
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('annotated', $settled['result']['outcome']);
        $this->assertTrue($settled['calc']['other_open_episodes']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_another_escalated_episode_not_yet_linked_keeps_the_incident_open(): void
    {
        $incident = $this->openIncident();
        $this->escalatedEpisode($incident);
        // Escaló hace poco: su evento ya se plegó al incidente, pero aún sin incident_id.
        $drive = $this->escalatedEpisode(null, HosSituation::DriveLimit);

        $this->correct(drive: 0);

        $this->assertNull($drive->fresh()->incident_id);
        $this->assertFalse($incident->fresh('status')->isTerminal());
        $settled = $this->assertSystemLogged('hos.incident.settled');
        $this->assertSame('annotated', $settled['result']['outcome']);
        $this->assertTrue($settled['calc']['other_open_episodes']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_settling_never_touches_another_tenants_incident(): void
    {
        $other = $this->hosIntegration();
        [$otherDriver, $otherAsset] = $this->hosDriver($other, '99000001', '999');
        $otherIncident = Incident::factory()->open()->create(['team_id' => $other->team_id]);
        HosEpisode::factory()->create([
            'team_id' => $other->team_id, 'driver_id' => $otherDriver->id, 'asset_id' => $otherAsset->id,
            'situation' => HosSituation::BreakDue, 'opened_at' => now()->subMinutes(30), 'escalated_at' => now()->subMinutes(10), 'incident_id' => $otherIncident->id,
        ]);
        $incident = $this->openIncident();
        $this->escalatedEpisode($incident);

        $this->assertNoTenantLeak($this->integration->team_id, fn () => $this->correct());

        $this->assertTrue($incident->fresh('status')->isTerminal());
        $this->assertFalse($otherIncident->fresh('status')->isTerminal());
        $this->assertNoSensitiveDataLogged();
    }
}
