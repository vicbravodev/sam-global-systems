<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sólo lo vigilado cuesta: un evento de una unidad `pending`/`excluded` se
 * descarta en normalización y nunca llega a IA, decisiones ni incidentes.
 */
class NormalizeRawEventMonitoringGateTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    private IntegrationProvider $provider;

    private EventType $eventType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = User::factory()->create()->currentTeam->id;
        $this->provider = IntegrationProvider::factory()->samsara()->create();

        $category = EventCategory::factory()->safety()->create();
        $severity = EventSeverity::factory()->medium()->create();
        $this->eventType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $category->id,
            'default_severity_id' => $severity->id,
        ]);
        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $this->eventType->id,
        ]);
    }

    private function providerEventFor(Asset $asset): RawEvent
    {
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->provider->id,
            'external_id' => "ext-{$asset->id}",
        ]);

        return RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->provider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['asset' => ['id' => "ext-{$asset->id}"]],
        ]);
    }

    public function test_an_event_of_a_pending_unit_is_discarded(): void
    {
        Event::fake([EventNormalized::class]);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->providerEventFor($asset);

        $normalized = app(NormalizeRawEvent::class)->execute($rawEvent);

        $this->assertNull($normalized);
        $this->assertSame(RawEventStatus::Discarded, $rawEvent->fresh()->status);
        $this->assertSame(0, NormalizedEvent::withoutGlobalScopes()->where('raw_event_id', $rawEvent->id)->count());
        Event::assertNotDispatched(EventNormalized::class);
    }

    public function test_an_event_of_an_excluded_unit_is_discarded(): void
    {
        $asset = Asset::factory()->excluded()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->providerEventFor($asset);

        $this->assertNull(app(NormalizeRawEvent::class)->execute($rawEvent));
        $this->assertSame(RawEventStatus::Discarded, $rawEvent->fresh()->status);
    }

    public function test_an_event_of_a_monitored_unit_flows(): void
    {
        Event::fake([EventNormalized::class]);
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $rawEvent = $this->providerEventFor($asset);

        $normalized = app(NormalizeRawEvent::class)->execute($rawEvent);

        $this->assertNotNull($normalized);
        $this->assertSame($asset->id, $normalized->asset_id);
        $this->assertSame(RawEventStatus::Processed, $rawEvent->fresh()->status);
        Event::assertDispatched(EventNormalized::class);
    }

    public function test_an_internal_monitor_event_of_a_pending_unit_is_discarded(): void
    {
        Event::fake([EventNormalized::class]);
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->teamId]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => null,
            'event_type_raw' => 'speeding',
            'payload_json' => ['internal' => ['asset_id' => $asset->id]],
        ]);

        $this->assertNull(app(NormalizeRawEvent::class)->execute($rawEvent));
        $this->assertSame(RawEventStatus::Discarded, $rawEvent->fresh()->status);
        Event::assertNotDispatched(EventNormalized::class);
    }
}
