<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Actions\CorrelateSafetyAlertEcho;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Un AlertIncident que Samsara dispara POR un safety event es su eco: se
 * registra sin abrir nada, se enlaza a su safety event y adelanta el poll
 * (spec 2026-10-04, alertas 03).
 */
class SafetyAlertEchoTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const string VEHICLE_ID = '281474993505355';

    private IntegrationProvider $samsara;

    private Team $team;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NormalizationSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->samsara = IntegrationProvider::query()->where('code', 'samsara')->firstOrFail();
        $this->team = Team::factory()->create();
        $this->integration = $this->integrationFor($this->team);
        $this->unitFor($this->team, self::VEHICLE_ID);
    }

    private function integrationFor(Team $team): TenantIntegration
    {
        return TenantIntegration::factory()->active()->create(['team_id' => $team->id, 'provider_id' => $this->samsara->id]);
    }

    private function unitFor(Team $team, string $externalId): Asset
    {
        $asset = Asset::factory()->create(['team_id' => $team->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->samsara->id,
            'external_id' => $externalId,
        ]);

        return $asset;
    }

    private function echoAlert(string $at, ?Team $team = null, ?string $vehicleId = self::VEHICLE_ID, int $triggerId = 5039): RawEvent
    {
        $team ??= $this->team;
        $details = $vehicleId === null ? [] : ['harshEvent' => ['vehicle' => ['id' => $vehicleId, 'name' => 'T-879']]];

        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $team->id,
            'provider_id' => $this->samsara->id,
            'event_type_raw' => 'AlertIncident',
            'occurred_at' => $at,
            'payload_json' => [
                'eventType' => 'AlertIncident',
                'eventId' => 'wh-'.$at.'-'.$team->id,
                'data' => [
                    'happenedAtTime' => $at,
                    'conditions' => [['triggerId' => $triggerId, 'description' => 'A safety event occurred', 'details' => $details]],
                ],
            ],
        ]);
    }

    private function safetyEvent(string $id, string $at, ?Team $team = null, string $vehicleId = self::VEHICLE_ID): RawEvent
    {
        $team ??= $this->team;

        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $team->id,
            'provider_id' => $this->samsara->id,
            'external_event_id' => $id,
            'deduplication_key' => 'safety:'.$id.':needsReview',
            'event_type_raw' => 'Braking',
            'occurred_at' => $at,
            'payload_json' => [
                'id' => $id,
                'eventState' => 'needsReview',
                'updatedAtTime' => $at,
                'asset' => ['id' => $vehicleId],
                'behaviorLabels' => [['label' => 'Braking', 'source' => 'SYSTEM']],
            ],
        ]);
    }

    private function normalize(RawEvent $raw): NormalizedEvent
    {
        (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));

        return NormalizedEvent::withoutGlobalScopes()->where('raw_event_id', $raw->id)->sole();
    }

    public function test_an_echo_that_arrives_first_asks_for_the_poll_and_links_when_the_event_lands(): void
    {
        Bus::fake([PollSafetyEventsJob::class]);

        $echo = $this->normalize($this->echoAlert('2026-10-04 10:00:00'));

        $this->assertSame('provider_safety_alert', $echo->eventType?->code);
        $this->assertArrayNotHasKey('echo_of_normalized_event_id', $echo->payload_normalized_json);
        Bus::assertDispatched(PollSafetyEventsJob::class, fn (PollSafetyEventsJob $job): bool => $job->integration->is($this->integration)
            && $job->delay !== null);
        $unmatched = $this->assertSystemLogged('normalization.safety_echo.unmatched');
        $this->assertSame('no_candidate', $unmatched['reason']);
        $this->assertSystemLogged('normalization.safety_echo.poll_requested');

        $event = $this->normalize($this->safetyEvent('evt-1', '2026-10-04 09:59:30'));

        $this->assertSame($event->id, $echo->fresh()?->payload_normalized_json['echo_of_normalized_event_id']);
        $c = $this->assertSystemLogged('normalization.safety_echo.correlated');
        $this->assertSame('alert_first', $c['calc']['direction']);
        $this->assertSame(30, $c['calc']['seconds_apart']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_echo_after_its_safety_event_links_right_away_without_polling(): void
    {
        Bus::fake([PollSafetyEventsJob::class]);

        $event = $this->normalize($this->safetyEvent('evt-1', '2026-10-04 10:00:00'));
        $echo = $this->normalize($this->echoAlert('2026-10-04 10:00:40'));

        $this->assertSame($event->id, $echo->payload_normalized_json['echo_of_normalized_event_id']);
        Bus::assertNotDispatched(PollSafetyEventsJob::class);
        $c = $this->assertSystemLogged('normalization.safety_echo.correlated');
        $this->assertSame('event_first', $c['calc']['direction']);
    }

    public function test_the_closest_safety_event_wins(): void
    {
        Bus::fake([PollSafetyEventsJob::class]);

        $this->normalize($this->safetyEvent('evt-far', '2026-10-04 09:58:30'));
        $near = $this->normalize($this->safetyEvent('evt-near', '2026-10-04 09:59:50'));
        $echo = $this->normalize($this->echoAlert('2026-10-04 10:00:00'));

        $this->assertSame($near->id, $echo->payload_normalized_json['echo_of_normalized_event_id']);
    }

    public function test_out_of_window_or_without_a_unit_never_links(): void
    {
        Bus::fake([PollSafetyEventsJob::class]);

        $this->normalize($this->safetyEvent('evt-old', '2026-10-04 09:50:00'));
        $late = $this->normalize($this->echoAlert('2026-10-04 10:00:00'));
        $this->assertArrayNotHasKey('echo_of_normalized_event_id', $late->payload_normalized_json);

        $noUnit = $this->normalize($this->echoAlert('2026-10-04 09:50:10', vehicleId: null));
        $this->assertArrayNotHasKey('echo_of_normalized_event_id', $noUnit->payload_normalized_json);
        $this->assertSystemLogged('normalization.safety_echo.unmatched', fn (array $c): bool => $c['reason'] === 'no_asset');
    }

    public function test_an_echo_never_reaches_ai_nor_opens_an_incident(): void
    {
        Bus::fake([PollSafetyEventsJob::class]);

        $echo = $this->normalize($this->echoAlert('2026-10-04 10:00:00'));

        $this->assertSame('safety', $echo->eventCategory?->code);
        $this->assertSame(0, AIEventEvaluation::withoutGlobalScopes()->count());
        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
    }

    public function test_another_tenants_safety_event_is_never_linked_and_only_our_poll_is_requested(): void
    {
        Bus::fake([PollSafetyEventsJob::class]);
        $other = Team::factory()->create();
        $otherIntegration = $this->integrationFor($other);
        $this->unitFor($other, self::VEHICLE_ID.'-b');

        // Misma hora; la unidad del otro tenant.
        $theirs = $this->normalize($this->safetyEvent('evt-b', '2026-10-04 10:00:00', $other, self::VEHICLE_ID.'-b'));

        $raw = $this->echoAlert('2026-10-04 10:00:05');
        $echo = $this->assertNoTenantLeak($this->team, fn () => $this->normalize($raw));

        $this->assertArrayNotHasKey('echo_of_normalized_event_id', $echo->payload_normalized_json);
        $this->assertArrayNotHasKey('echo_of_normalized_event_id', $theirs->fresh()?->payload_normalized_json ?? []);
        Bus::assertDispatched(PollSafetyEventsJob::class, fn (PollSafetyEventsJob $job): bool => $job->integration->is($this->integration));
        Bus::assertNotDispatched(PollSafetyEventsJob::class, fn (PollSafetyEventsJob $job): bool => $job->integration->is($otherIntegration));
    }

    public function test_events_that_are_neither_echo_nor_safety_feed_are_ignored(): void
    {
        $panic = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        app(CorrelateSafetyAlertEcho::class)->execute($panic);

        $this->assertSystemNotLogged('normalization.safety_echo.correlated');
        $this->assertSystemNotLogged('normalization.safety_echo.unmatched');
    }
}
