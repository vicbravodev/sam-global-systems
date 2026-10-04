<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\NormalizedEventUpdated;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Un safety event de Samsara es UNA entidad: cada cambio de estado o de
 * etiqueta llega como raw event nuevo y actualiza la misma fila normalizada
 * (spec 2026-10-04, alertas 04).
 */
class SafetyEventEntityTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const string EVENT_ID = '5445f467-47e6-51cc-a482-af49d0ad10f9';

    private const string VEHICLE_ID = '281474993505355';

    private IntegrationProvider $samsara;

    private Team $team;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NormalizationSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->samsara = IntegrationProvider::query()->where('code', 'samsara')->firstOrFail();
        $this->team = Team::factory()->create();
        $this->asset = $this->assetFor($this->team);
    }

    private function assetFor(Team $team, string $externalId = self::VEHICLE_ID): Asset
    {
        $asset = Asset::factory()->create(['team_id' => $team->id]);

        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->samsara->id,
            'external_id' => $team->id === $this->team->id ? $externalId : $externalId.'-'.$team->id,
        ]);

        return $asset;
    }

    /**
     * Una entrega del stream `/safety-events/stream`, como la guarda
     * IngestSafetyEvent.
     */
    private function delivery(string $state, string $label, string $updatedAt, ?Team $team = null, string $vehicleId = self::VEHICLE_ID): RawEvent
    {
        $team ??= $this->team;

        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $team->id,
            'provider_id' => $this->samsara->id,
            'external_event_id' => self::EVENT_ID,
            'deduplication_key' => 'safety:'.self::EVENT_ID.':'.$state,
            'event_type_raw' => $label,
            'occurred_at' => '2026-10-04 10:00:00',
            'payload_json' => [
                'id' => self::EVENT_ID,
                'eventState' => $state,
                'createdAtTime' => '2026-10-04T10:00:00Z',
                'updatedAtTime' => $updatedAt,
                'asset' => ['id' => $vehicleId],
                'behaviorLabels' => [['label' => $label, 'source' => 'SYSTEM']],
            ],
        ]);
    }

    private function normalize(RawEvent $raw): void
    {
        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));
    }

    public function test_state_changes_update_one_row(): void
    {
        Event::fake([EventNormalized::class, NormalizedEventUpdated::class]);

        $first = $this->delivery('needsReview', 'Braking', '2026-10-04T10:00:05Z');
        $reviewed = $this->delivery('reviewed', 'Braking', '2026-10-04T11:00:00Z');
        $dismissed = $this->delivery('dismissed', 'Braking', '2026-10-04T12:00:00Z');

        foreach ([$first, $reviewed, $dismissed] as $raw) {
            $this->normalize($raw);
        }

        $row = NormalizedEvent::withoutGlobalScopes()->sole();
        $this->assertSame('safety:'.self::EVENT_ID, $row->provider_event_key);
        $this->assertSame('dismissed', $row->provider_state);
        $this->assertSame('2026-10-04 12:00:00', $row->provider_dismissed_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($dismissed->id, $row->raw_event_id);
        $this->assertSame('2026-10-04 10:00:00', $row->occurred_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($row->payload_normalized_json['is_resolved']);

        foreach ([$first, $reviewed, $dismissed] as $raw) {
            $this->assertSame(RawEventStatus::Processed, $raw->fresh()?->status);
        }

        // El pipeline completo (contexto, media, IA) corre una sola vez; los
        // cambios de estado sólo avisan que la fila cambió.
        Event::assertDispatchedTimes(EventNormalized::class, 1);
        Event::assertDispatchedTimes(NormalizedEventUpdated::class, 2);

        $c = $this->assertSystemLogged('normalization.safety_event.updated', fn (array $c): bool => $c['calc']['state_to'] === 'dismissed');
        $this->assertSame('reviewed', $c['calc']['state_from']);
        $this->assertFalse($c['calc']['label_changed']);
        $this->assertFalse($c['calc']['became_emergency']);
        $this->assertSame($row->id, $c['input']['normalized_event_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_older_state_reprocessed_never_overwrites_a_newer_one(): void
    {
        Event::fake([EventNormalized::class, NormalizedEventUpdated::class]);

        $old = $this->delivery('needsReview', 'Braking', '2026-10-04T10:00:05Z');
        $new = $this->delivery('reviewed', 'Braking', '2026-10-04T11:00:00Z');
        $this->normalize($old);
        $this->normalize($new);

        // El rescate del pipeline vuelve a encolar el raw viejo.
        $old->forceFill(['status' => RawEventStatus::PendingProcessing])->save();
        $this->normalize($old);

        $row = NormalizedEvent::withoutGlobalScopes()->sole();
        $this->assertSame('reviewed', $row->provider_state);
        $this->assertSame($new->id, $row->raw_event_id);
        $this->assertSame(RawEventStatus::Processed, $old->fresh()?->status);

        $c = $this->assertSystemLogged('normalization.safety_event.update_skipped');
        $this->assertSame('stale_state', $c['reason']);
    }

    public function test_a_label_change_between_non_emergencies_updates_the_type_only(): void
    {
        Event::fake([EventNormalized::class, NormalizedEventUpdated::class]);

        $this->normalize($this->delivery('needsReview', 'Braking', '2026-10-04T10:00:05Z'));
        $this->normalize($this->delivery('reviewed', 'Invalid', '2026-10-04T11:00:00Z'));

        $row = NormalizedEvent::withoutGlobalScopes()->with('eventType')->sole();
        $this->assertSame('other_violation', $row->eventType?->code);
        Event::assertDispatchedTimes(EventNormalized::class, 1);

        $c = $this->assertSystemLogged('normalization.safety_event.updated');
        $this->assertTrue($c['calc']['label_changed']);
    }

    public function test_a_review_that_turns_it_into_a_crash_opens_the_emergency_incident(): void
    {
        $this->normalize($this->delivery('needsReview', 'Braking', '2026-10-04T10:00:05Z'));
        $this->assertSame(0, Incident::withoutGlobalScopes()->count());

        $this->normalize($this->delivery('reviewed', 'Crash', '2026-10-04T11:00:00Z'));

        $row = NormalizedEvent::withoutGlobalScopes()->with('eventType')->sole();
        $this->assertSame('collision', $row->eventType?->code);
        $this->assertTrue(Incident::withoutGlobalScopes()->where('team_id', $this->team->id)->where('related_event_id', $row->id)->exists());

        $c = $this->assertSystemLogged('normalization.safety_event.updated');
        $this->assertTrue($c['calc']['became_emergency']);
    }

    public function test_a_crash_dismissed_at_the_provider_resolves_its_own_incident_only(): void
    {
        $this->normalize($this->delivery('needsReview', 'Crash', '2026-10-04T10:00:05Z'));
        $row = NormalizedEvent::withoutGlobalScopes()->sole();
        $crashIncident = Incident::withoutGlobalScopes()->where('related_event_id', $row->id)->sole();

        // Un pánico abierto de la misma unidad, sin relación con el choque.
        $panicType = EventType::query()->where('code', 'panic_button')->firstOrFail();
        $panicEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'asset_id' => $this->asset->id,
            'event_type_id' => $panicType->id,
            'event_category_id' => $panicType->category_id,
            'event_severity_id' => $panicType->default_severity_id,
            'occurred_at' => '2026-10-04 10:05:00',
        ]);
        $panicIncident = app(CreateIncidentFromEvent::class)->execute($panicEvent);
        $this->assertNotSame($crashIncident->id, $panicIncident->id);

        $this->normalize($this->delivery('dismissed', 'Crash', '2026-10-04T10:30:00Z'));

        $this->assertNotNull($crashIncident->fresh()?->external_resolved_at);
        $this->assertNull($panicIncident->fresh()?->external_resolved_at);

        $c = $this->assertSystemLogged('incidents.external_resolution.matched', fn (array $c): bool => ($c['calc']['strategy'] ?? null) === 'same_event');
        $this->assertSame([$crashIncident->id], $c['result']['incident_ids']);
    }

    public function test_the_same_provider_event_id_in_two_tenants_never_shares_a_row(): void
    {
        Event::fake([EventNormalized::class, NormalizedEventUpdated::class]);
        $other = Team::factory()->create();
        $this->assetFor($other);

        $this->normalize($this->delivery('needsReview', 'Braking', '2026-10-04T10:00:05Z', $other, self::VEHICLE_ID.'-'.$other->id));
        $theirs = NormalizedEvent::withoutGlobalScopes()->where('team_id', $other->id)->sole();

        $mine = $this->delivery('needsReview', 'Braking', '2026-10-04T10:00:05Z');
        $this->assertNoTenantLeak($this->team, fn () => $this->normalize($mine));
        $dismissed = $this->delivery('dismissed', 'Braking', '2026-10-04T12:00:00Z');
        $this->assertNoTenantLeak($this->team, fn () => $this->normalize($dismissed));

        $this->assertSame(2, NormalizedEvent::withoutGlobalScopes()->count());
        $this->assertSame('needsReview', $theirs->fresh()?->provider_state);
        $this->assertSame('dismissed', NormalizedEvent::withoutGlobalScopes()->where('team_id', $this->team->id)->sole()->provider_state);
    }

    public function test_countable_excludes_dismissed_and_superseded_rows(): void
    {
        Event::fake([EventNormalized::class, NormalizedEventUpdated::class]);
        $this->normalize($this->delivery('needsReview', 'Braking', '2026-10-04T10:00:05Z'));
        $plain = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        $superseded = NormalizedEvent::factory()->create(['team_id' => $this->team->id, 'provider_state' => 'superseded']);

        $this->assertSame(2, NormalizedEvent::withoutGlobalScopes()->countable()->count());

        $this->normalize($this->delivery('dismissed', 'Braking', '2026-10-04T12:00:00Z'));

        $ids = NormalizedEvent::withoutGlobalScopes()->countable()->pluck('id')->all();
        $this->assertSame([$plain->id], $ids);
        $this->assertNotContains($superseded->id, $ids);
    }

    public function test_a_dismissal_never_resolves_another_tenants_incident_for_the_same_provider_event(): void
    {
        $other = Team::factory()->create();
        $this->assetFor($other);

        $this->normalize($this->delivery('needsReview', 'Crash', '2026-10-04T10:00:05Z', $other, self::VEHICLE_ID.'-'.$other->id));
        $theirs = NormalizedEvent::withoutGlobalScopes()->where('team_id', $other->id)->sole();
        $theirIncident = Incident::withoutGlobalScopes()->where('related_event_id', $theirs->id)->sole();

        $this->normalize($this->delivery('needsReview', 'Crash', '2026-10-04T10:00:05Z'));
        $mine = NormalizedEvent::withoutGlobalScopes()->where('team_id', $this->team->id)->sole();
        $myIncident = Incident::withoutGlobalScopes()->where('related_event_id', $mine->id)->sole();

        $dismissed = $this->delivery('dismissed', 'Crash', '2026-10-04T10:30:00Z');
        $this->assertNoTenantLeak($this->team, fn () => $this->normalize($dismissed));

        $this->assertNotNull($myIncident->fresh()?->external_resolved_at);
        $this->assertNull($theirIncident->fresh()?->external_resolved_at);
        $this->assertSame('needsReview', $theirs->fresh()?->provider_state);
    }
}
