<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\EventUnmapped;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Arr;

class NormalizeRawEvent
{
    public function __construct(
        private MapExternalEventType $mapExternalEventType,
        private ResolveEventSeverity $resolveEventSeverity,
    ) {}

    public function execute(RawEvent $rawEvent): NormalizedEvent
    {
        $externalEventType = $rawEvent->event_type_raw ?? '';
        $providerId = $rawEvent->provider_id;
        $payload = $rawEvent->payload_json ?? [];

        $rule = $providerId
            ? $this->mapExternalEventType->execute($providerId, $externalEventType, $payload)
            : null;

        if (! $rule) {
            return $this->createUnmappedEvent($rawEvent, $externalEventType, $providerId);
        }

        return $this->createNormalizedEvent($rawEvent, $rule, $payload);
    }

    private function createUnmappedEvent(
        RawEvent $rawEvent,
        string $externalEventType,
        ?int $providerId,
    ): NormalizedEvent {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()->updateOrCreate(
            ['raw_event_id' => $rawEvent->id],
            [
                'team_id' => $rawEvent->team_id,
                'provider_id' => $rawEvent->provider_id,
                'asset_id' => null,
                'driver_id' => null,
                'event_type_id' => $this->getUnmappedEventTypeId(),
                'event_category_id' => $this->getUnmappedCategoryId(),
                'event_severity_id' => $this->getUnmappedSeverityId(),
                'occurred_at' => $rawEvent->occurred_at ?? $rawEvent->received_at,
                'processed_at' => now(),
                'payload_normalized_json' => $rawEvent->payload_json ?? [],
                'status' => NormalizedEventStatus::Unmapped,
            ],
        );

        $rawEvent->markAsProcessed();

        EventUnmapped::dispatch($rawEvent, $externalEventType, $providerId ?? 0);

        return $normalizedEvent;
    }

    private function createNormalizedEvent(
        RawEvent $rawEvent,
        EventMappingRule $rule,
        array $payload,
    ): NormalizedEvent {
        $eventType = $rule->mappedEventType;
        $severity = $this->resolveEventSeverity->execute($rule, $eventType);
        $category = $rule->mapped_category_id
            ? $rule->mappedCategory
            : $eventType->category;

        $assetId = $this->resolveAssetId($rawEvent->provider_id, $payload);
        $driverId = $this->resolveDriverId($rawEvent->provider_id, $payload);

        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()->updateOrCreate(
            ['raw_event_id' => $rawEvent->id],
            [
                'team_id' => $rawEvent->team_id,
                'provider_id' => $rawEvent->provider_id,
                'asset_id' => $assetId,
                'driver_id' => $driverId,
                'event_type_id' => $eventType->id,
                'event_category_id' => $category->id,
                'event_severity_id' => $severity->id,
                'occurred_at' => $rawEvent->occurred_at ?? $rawEvent->received_at,
                'processed_at' => now(),
                'payload_normalized_json' => $this->buildNormalizedPayload($rawEvent, $eventType, $severity, $payload),
                'status' => NormalizedEventStatus::Normalized,
            ],
        );

        $rawEvent->markAsProcessed();

        EventNormalized::dispatch($normalizedEvent);

        return $normalizedEvent;
    }

    /**
     * Resolve asset ID from external references using provider-specific identifiers in the payload.
     *
     * Priority chain:
     * 1. payload.asset.id (Safety Event stream)
     * 2. payload.vehicle.id (AlertIncident root)
     * 3. payload.vehicleId (AlertIncident alternative)
     * 4. payload.data.conditions.0.details.panicButton.vehicle.id (AlertIncident nested)
     */
    private function resolveAssetId(?int $providerId, array $payload): ?int
    {
        if (! $providerId) {
            return null;
        }

        $externalId = Arr::get($payload, 'asset.id')
            ?? Arr::get($payload, 'vehicle.id')
            ?? Arr::get($payload, 'vehicleId')
            ?? Arr::get($payload, 'data.conditions.0.details.panicButton.vehicle.id');

        if (! $externalId) {
            return null;
        }

        $reference = AssetExternalReference::query()
            ->where('provider_id', $providerId)
            ->where('external_id', (string) $externalId)
            ->first();

        return $reference?->asset_id;
    }

    /**
     * Resolve driver ID from external references using provider-specific identifiers in the payload.
     *
     * Priority chain:
     * 1. payload.driver.id (both formats at root)
     * 2. payload.data.conditions.0.details.panicButton.driver.id (AlertIncident nested)
     */
    private function resolveDriverId(?int $providerId, array $payload): ?int
    {
        if (! $providerId) {
            return null;
        }

        $externalId = Arr::get($payload, 'driver.id')
            ?? Arr::get($payload, 'data.conditions.0.details.panicButton.driver.id');

        if (! $externalId) {
            return null;
        }

        $reference = DriverExternalReference::query()
            ->where('provider_id', $providerId)
            ->where('external_id', (string) $externalId)
            ->first();

        return $reference?->driver_id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildNormalizedPayload(RawEvent $rawEvent, $eventType, EventSeverity $severity, array $payload): array
    {
        return [
            'event_type_code' => $eventType->code,
            'severity_code' => $severity->code,
            'external_event_type' => $rawEvent->event_type_raw,
            'description' => Arr::get($payload, 'data.conditions.0.description')
                ?? Arr::get($payload, 'behaviorLabels.0.label')
                ?? $rawEvent->event_type_raw,
            'occurred_at' => ($rawEvent->occurred_at ?? $rawEvent->received_at)->toIso8601String(),
            'location' => Arr::get($payload, 'location'),
            'speed_metadata' => Arr::get($payload, 'speedingMetadata'),
            'incident_url' => Arr::get($payload, 'data.incidentUrl')
                ?? Arr::get($payload, 'incidentReportUrl')
                ?? Arr::get($payload, 'inboxEventUrl'),
            'is_resolved' => Arr::get($payload, 'data.isResolved'),
            'raw_conditions' => Arr::get($payload, 'data.conditions'),
            'raw_behavior_labels' => Arr::get($payload, 'behaviorLabels'),
        ];
    }

    private function getUnmappedEventTypeId(): int
    {
        return EventType::where('code', 'unmapped')
            ->value('id')
            ?? EventType::query()->value('id');
    }

    private function getUnmappedCategoryId(): int
    {
        return EventCategory::where('code', 'operational')
            ->value('id')
            ?? EventCategory::query()->value('id');
    }

    private function getUnmappedSeverityId(): int
    {
        return EventSeverity::where('code', 'low')
            ->value('id')
            ?? EventSeverity::query()->value('id');
    }
}
