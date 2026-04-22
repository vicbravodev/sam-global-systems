<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\EventUnmapped;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NormalizeRawEventTest extends TestCase
{
    use RefreshDatabase;

    private EventCategory $safetyCategory;

    private EventCategory $emergencyCategory;

    private EventCategory $operationalCategory;

    private EventSeverity $lowSeverity;

    private EventSeverity $mediumSeverity;

    private EventSeverity $highSeverity;

    private EventSeverity $criticalSeverity;

    private IntegrationProvider $samsaraProvider;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;

        $this->safetyCategory = EventCategory::factory()->safety()->create();
        $this->emergencyCategory = EventCategory::factory()->emergency()->create();
        $this->operationalCategory = EventCategory::factory()->operational()->create();

        $this->lowSeverity = EventSeverity::factory()->low()->create();
        $this->mediumSeverity = EventSeverity::factory()->medium()->create();
        $this->highSeverity = EventSeverity::factory()->high()->create();
        $this->criticalSeverity = EventSeverity::factory()->critical()->create();

        $this->samsaraProvider = IntegrationProvider::factory()->samsara()->create();
    }

    public function test_raw_event_normalizes_to_correct_type_and_severity(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        $panicType = EventType::factory()->create([
            'code' => 'panic_button',
            'name' => 'Panic Button',
            'category_id' => $this->emergencyCategory->id,
            'default_severity_id' => $this->criticalSeverity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->samsaraProvider->id,
            'external_event_type' => 'AlertIncident',
            'external_conditions_json' => ['data.conditions.0.description' => 'Panic Button'],
            'mapped_event_type_id' => $panicType->id,
            'priority' => 10,
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'AlertIncident',
            'payload_json' => [
                'eventType' => 'AlertIncident',
                'eventId' => 'evt-001',
                'data' => [
                    'conditions' => [['description' => 'Panic Button']],
                    'happenedAtTime' => '2026-04-12T14:41:56+00:00',
                ],
            ],
        ]);

        $action = app(NormalizeRawEvent::class);
        $normalized = $action->execute($rawEvent);

        $this->assertEquals(
            $panicType->id,
            $normalized->event_type_id,
            'NormalizedEvent should map to the panic_button event type via AlertIncident condition matching',
        );

        $this->assertEquals(
            $this->criticalSeverity->id,
            $normalized->event_severity_id,
            'NormalizedEvent severity should be critical for panic_button (type default)',
        );

        $this->assertEquals(
            $this->emergencyCategory->id,
            $normalized->event_category_id,
            'NormalizedEvent category should be emergency for panic_button',
        );

        $this->assertEquals(
            NormalizedEventStatus::Normalized,
            $normalized->status,
            'NormalizedEvent status should be normalized after successful mapping',
        );
    }

    public function test_safety_event_stream_normalizes_with_behavior_label(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        $speedingType = EventType::factory()->create([
            'code' => 'speeding',
            'name' => 'Speeding',
            'category_id' => $this->safetyCategory->id,
            'default_severity_id' => $this->mediumSeverity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->samsaraProvider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $speedingType->id,
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => [
                'id' => '544540e6-59cc-5d98-a0e5-6889838205cb',
                'asset' => ['id' => '281474992891156'],
                'driver' => ['id' => '53442787'],
                'behaviorLabels' => [['label' => 'MaxSpeed', 'source' => 'SYSTEM']],
                'location' => ['latitude' => 32.822863, 'longitude' => -85.212265],
                'speedingMetadata' => [
                    'maxSpeedKilometersPerHour' => 116,
                    'postedSpeedLimitKilometersPerHour' => 110,
                ],
            ],
        ]);

        $action = app(NormalizeRawEvent::class);
        $normalized = $action->execute($rawEvent);

        $this->assertEquals(
            $speedingType->id,
            $normalized->event_type_id,
            'Safety event with MaxSpeed behavior label should map to speeding event type',
        );

        $this->assertEquals(
            $this->mediumSeverity->id,
            $normalized->event_severity_id,
            'Speeding event should have medium severity from type default',
        );

        $this->assertEquals(
            NormalizedEventStatus::Normalized,
            $normalized->status,
            'Safety event stream payload should produce a normalized status',
        );
    }

    public function test_unmapped_event_type_creates_unmapped_normalized_event(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        EventType::factory()->create([
            'code' => 'unmapped',
            'category_id' => $this->operationalCategory->id,
            'default_severity_id' => $this->lowSeverity->id,
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'CompletelyUnknownEvent',
            'payload_json' => ['eventType' => 'CompletelyUnknownEvent'],
        ]);

        $action = app(NormalizeRawEvent::class);
        $normalized = $action->execute($rawEvent);

        $this->assertEquals(
            NormalizedEventStatus::Unmapped,
            $normalized->status,
            'RawEvent with no matching mapping rule should produce a NormalizedEvent with unmapped status',
        );

        Event::assertDispatched(EventUnmapped::class, function ($event) use ($rawEvent) {
            return $event->rawEvent->id === $rawEvent->id
                && $event->externalEventType === 'CompletelyUnknownEvent';
        });
    }

    public function test_normalization_resolves_asset_from_external_id(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        $speedingType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $this->safetyCategory->id,
            'default_severity_id' => $this->mediumSeverity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->samsaraProvider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $speedingType->id,
        ]);

        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->samsaraProvider->id,
            'external_id' => '281474992891156',
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => [
                'asset' => ['id' => '281474992891156'],
                'behaviorLabels' => [['label' => 'MaxSpeed', 'source' => 'SYSTEM']],
            ],
        ]);

        $action = app(NormalizeRawEvent::class);
        $normalized = $action->execute($rawEvent);

        $this->assertEquals(
            $asset->id,
            $normalized->asset_id,
            'NormalizedEvent should resolve asset_id from asset.id in stream payload via AssetExternalReference lookup',
        );
    }

    public function test_normalization_resolves_driver_from_external_id(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        $speedingType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $this->safetyCategory->id,
            'default_severity_id' => $this->mediumSeverity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->samsaraProvider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $speedingType->id,
        ]);

        $driver = Driver::factory()->create(['team_id' => $this->teamId]);
        DriverExternalReference::factory()->create([
            'driver_id' => $driver->id,
            'provider_id' => $this->samsaraProvider->id,
            'external_id' => '53442787',
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => [
                'driver' => ['id' => '53442787'],
                'behaviorLabels' => [['label' => 'MaxSpeed', 'source' => 'SYSTEM']],
            ],
        ]);

        $action = app(NormalizeRawEvent::class);
        $normalized = $action->execute($rawEvent);

        $this->assertEquals(
            $driver->id,
            $normalized->driver_id,
            'NormalizedEvent should resolve driver_id from driver.id in payload via DriverExternalReference lookup',
        );
    }

    public function test_duplicate_normalization_does_not_create_second_record(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        $speedingType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $this->safetyCategory->id,
            'default_severity_id' => $this->mediumSeverity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->samsaraProvider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $speedingType->id,
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['behaviorLabels' => [['label' => 'MaxSpeed', 'source' => 'SYSTEM']]],
        ]);

        $action = app(NormalizeRawEvent::class);
        $first = $action->execute($rawEvent);

        $rawEvent->refresh();
        $rawEvent->update(['status' => RawEventStatus::PendingProcessing]);

        $second = $action->execute($rawEvent);

        $this->assertEquals(
            $first->id,
            $second->id,
            'Re-normalizing the same RawEvent should update the existing NormalizedEvent, not create a duplicate',
        );

        $totalCount = NormalizedEvent::withoutGlobalScopes()
            ->where('raw_event_id', $rawEvent->id)
            ->count();

        $this->assertEquals(
            1,
            $totalCount,
            'There should be exactly one NormalizedEvent row per RawEvent thanks to updateOrCreate on raw_event_id',
        );
    }

    public function test_raw_event_status_updated_to_processed(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        $speedingType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $this->safetyCategory->id,
            'default_severity_id' => $this->mediumSeverity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->samsaraProvider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $speedingType->id,
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['behaviorLabels' => [['label' => 'MaxSpeed', 'source' => 'SYSTEM']]],
        ]);

        $action = app(NormalizeRawEvent::class);
        $action->execute($rawEvent);

        $rawEvent->refresh();

        $this->assertEquals(
            RawEventStatus::Processed,
            $rawEvent->status,
            'RawEvent status should be updated to processed after successful normalization',
        );
    }

    public function test_event_normalized_domain_event_dispatched(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        $speedingType = EventType::factory()->create([
            'code' => 'speeding',
            'category_id' => $this->safetyCategory->id,
            'default_severity_id' => $this->mediumSeverity->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->samsaraProvider->id,
            'external_event_type' => 'MaxSpeed',
            'mapped_event_type_id' => $speedingType->id,
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'MaxSpeed',
            'payload_json' => ['behaviorLabels' => [['label' => 'MaxSpeed', 'source' => 'SYSTEM']]],
        ]);

        $action = app(NormalizeRawEvent::class);
        $normalized = $action->execute($rawEvent);

        Event::assertDispatched(EventNormalized::class, function ($event) use ($normalized) {
            return $event->normalizedEvent->id === $normalized->id;
        });
    }

    public function test_event_unmapped_domain_event_dispatched(): void
    {
        Event::fake([EventNormalized::class, EventUnmapped::class]);

        EventType::factory()->create([
            'code' => 'unmapped',
            'category_id' => $this->operationalCategory->id,
            'default_severity_id' => $this->lowSeverity->id,
        ]);

        $rawEvent = RawEvent::factory()->pendingProcessing()->create([
            'team_id' => $this->teamId,
            'provider_id' => $this->samsaraProvider->id,
            'event_type_raw' => 'SomeUnknownLabel',
            'payload_json' => ['behaviorLabels' => [['label' => 'SomeUnknownLabel', 'source' => 'SYSTEM']]],
        ]);

        $action = app(NormalizeRawEvent::class);
        $action->execute($rawEvent);

        Event::assertDispatched(EventUnmapped::class, function ($event) use ($rawEvent) {
            return $event->rawEvent->id === $rawEvent->id
                && $event->externalEventType === 'SomeUnknownLabel'
                && $event->providerId === $this->samsaraProvider->id;
        });
    }
}
