<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\EventUnmapped;
use App\Domains\Normalization\Events\UnmonitoredAssetEmergencyReceived;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\PipelineTrace;
use Illuminate\Support\Arr;

class NormalizeRawEvent
{
    public const string EMERGENCY_CATEGORY = 'emergency';

    /** @var array<int, string> */
    public const array EMERGENCY_EVENT_TYPES = ['panic_button', 'collision', 'rollover_protection'];

    public function __construct(
        private MapExternalEventType $mapExternalEventType,
        private ResolveEventSeverity $resolveEventSeverity,
    ) {}

    /**
     * Null when the event belongs to an asset the tenant is not monitoring:
     * the raw event is marked `discarded` and never reaches enrichment, AI,
     * decisions or incidents. Only monitored assets are billed, so only
     * monitored assets may cost anything downstream — except emergencies,
     * which always flow and are charged as an extra asset-day.
     */
    public function execute(RawEvent $rawEvent): ?NormalizedEvent
    {
        $externalEventType = $rawEvent->event_type_raw ?? '';
        $providerId = $rawEvent->provider_id;
        $payload = $rawEvent->payload_json ?? [];

        $rule = $providerId
            ? $this->mapExternalEventType->execute($providerId, $externalEventType, $payload)
            : null;

        if (! $rule) {
            // Internal monitor events (Roadmap V2-C1) carry no provider and
            // need no mapping rule: their `event_type_raw` IS the event type
            // code and the asset comes pre-resolved in the payload.
            $internalType = $this->resolveInternalEventType($rawEvent, $payload);

            if ($internalType !== null) {
                return $this->createInternalNormalizedEvent($rawEvent, $internalType, $payload);
            }

            return $this->createUnmappedEvent($rawEvent, $externalEventType, $providerId);
        }

        return $this->createNormalizedEvent($rawEvent, $rule, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveInternalEventType(RawEvent $rawEvent, array $payload): ?EventType
    {
        if (! is_numeric(Arr::get($payload, 'internal.asset_id'))) {
            return null;
        }

        $code = (string) ($rawEvent->event_type_raw ?? '');

        if ($code === '') {
            return null;
        }

        return EventType::query()->where('code', $code)->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createInternalNormalizedEvent(
        RawEvent $rawEvent,
        EventType $eventType,
        array $payload,
    ): ?NormalizedEvent {
        $severity = $eventType->defaultSeverity ?? EventSeverity::query()->orderBy('level')->firstOrFail();
        $assetId = $this->resolveInternalAssetId($rawEvent, $payload);

        if ($this->assetIsSwitchedOff($assetId)) {
            $this->discard($rawEvent);

            return null;
        }

        $normalizedEvent = NormalizedEvent::query()->updateOrCreate(
            ['raw_event_id' => $rawEvent->id],
            [
                'team_id' => $rawEvent->team_id,
                'trace_id' => $rawEvent->trace_id,
                'provider_id' => null,
                'asset_id' => $assetId,
                'driver_id' => null,
                'event_type_id' => $eventType->id,
                'event_category_id' => $eventType->category?->id ?? $this->getUnmappedCategoryId(),
                'event_severity_id' => $severity->id,
                'occurred_at' => $rawEvent->occurred_at ?? $rawEvent->received_at,
                'processed_at' => now(),
                'payload_normalized_json' => $this->buildNormalizedPayload($rawEvent, $eventType, $severity, $payload),
                'status' => NormalizedEventStatus::Normalized,
            ],
        );

        $rawEvent->markAsProcessed();

        PipelineTrace::add(['normalized_event_id' => $normalizedEvent->id]);

        EventNormalized::dispatch($normalizedEvent);

        return $normalizedEvent;
    }

    /**
     * The internal asset id is only honored when the asset belongs to the raw
     * event's tenant — a forged payload can never bind a foreign asset.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveInternalAssetId(RawEvent $rawEvent, array $payload): ?int
    {
        $assetId = (int) Arr::get($payload, 'internal.asset_id');

        $belongs = Asset::query()
            ->whereKey($assetId)
            ->where('team_id', $rawEvent->team_id)
            ->exists();

        return $belongs ? $assetId : null;
    }

    private function createUnmappedEvent(
        RawEvent $rawEvent,
        string $externalEventType,
        ?int $providerId,
    ): NormalizedEvent {
        $normalizedEvent = NormalizedEvent::query()->updateOrCreate(
            ['raw_event_id' => $rawEvent->id],
            [
                'team_id' => $rawEvent->team_id,
                'trace_id' => $rawEvent->trace_id,
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

        PipelineTrace::add(['normalized_event_id' => $normalizedEvent->id]);

        EventUnmapped::dispatch($rawEvent, $externalEventType, $providerId ?? 0);

        return $normalizedEvent;
    }

    private function createNormalizedEvent(
        RawEvent $rawEvent,
        EventMappingRule $rule,
        array $payload,
    ): ?NormalizedEvent {
        $eventType = $rule->mappedEventType;
        $severity = $this->resolveEventSeverity->execute($rule, $eventType);
        $category = $rule->mapped_category_id
            ? $rule->mappedCategory
            : $eventType->category;

        $assetId = $this->resolveAssetId($rawEvent->provider_id, $rawEvent->team_id, $payload);

        // Una emergencia (pánico, colisión, vuelco) SIEMPRE se atiende, esté o
        // no vigilada la unidad (decisión 2026-09-28): la prioridad es la
        // persona. Ese día la unidad se cobra como extra (tracto-día + recargo)
        // y se avisa al admin. Todo lo demás de una unidad apagada se descarta.
        $unmonitored = $this->assetIsSwitchedOff($assetId);

        if ($unmonitored && ! $this->isEmergency($eventType, $category)) {
            $this->discard($rawEvent);

            return null;
        }

        $driverId = $this->resolveDriverId($rawEvent->provider_id, $rawEvent->team_id, $payload);

        $normalizedEvent = NormalizedEvent::query()->updateOrCreate(
            ['raw_event_id' => $rawEvent->id],
            [
                'team_id' => $rawEvent->team_id,
                'trace_id' => $rawEvent->trace_id,
                'provider_id' => $rawEvent->provider_id,
                'asset_id' => $assetId,
                'driver_id' => $driverId,
                'event_type_id' => $eventType->id,
                'event_category_id' => $category->id,
                'event_severity_id' => $severity->id,
                'occurred_at' => $rawEvent->occurred_at ?? $rawEvent->received_at,
                'processed_at' => now(),
                'payload_normalized_json' => [
                    ...$this->buildNormalizedPayload($rawEvent, $eventType, $severity, $payload),
                    ...($unmonitored ? ['unmonitored_asset' => true] : []),
                ],
                'status' => NormalizedEventStatus::Normalized,
            ],
        );

        $rawEvent->markAsProcessed();

        PipelineTrace::add(['normalized_event_id' => $normalizedEvent->id]);

        EventNormalized::dispatch($normalizedEvent);

        if ($unmonitored) {
            UnmonitoredAssetEmergencyReceived::dispatch($normalizedEvent);
        }

        return $normalizedEvent;
    }

    /**
     * Tipos que nunca se descartan por `monitoring_state`: la categoría
     * `emergency` del catálogo (pánico, colisión, vuelco).
     */
    public static function isEmergencyCode(?string $categoryCode, ?string $eventTypeCode): bool
    {
        return $categoryCode === self::EMERGENCY_CATEGORY
            || in_array($eventTypeCode, self::EMERGENCY_EVENT_TYPES, true);
    }

    private function isEmergency(?EventType $eventType, ?EventCategory $category): bool
    {
        return self::isEmergencyCode($category?->code, $eventType?->code);
    }

    /**
     * A resolved asset the tenant has not switched on (`pending`) or has
     * switched off (`excluded`). Events with no asset at all still flow:
     * they cost nothing per unit and may be fleet-wide provider notices.
     */
    private function assetIsSwitchedOff(?int $assetId): bool
    {
        if ($assetId === null) {
            return false;
        }

        return Asset::query()
            ->whereKey($assetId)
            ->monitored()
            ->doesntExist();
    }

    private function discard(RawEvent $rawEvent): void
    {
        $rawEvent->markAsStatus(RawEventStatus::Discarded);
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
    private function resolveAssetId(?int $providerId, ?int $teamId, array $payload): ?int
    {
        if (! $providerId || ! $teamId) {
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

        if ($reference === null) {
            return null;
        }

        // (provider_id, external_id) is unique platform-wide, so a payload id
        // can point at another tenant's asset. Isolation must not depend on
        // how the provider allocates its identifiers.
        $belongs = Asset::query()
            ->whereKey($reference->asset_id)
            ->where('team_id', $teamId)
            ->exists();

        return $belongs ? $reference->asset_id : null;
    }

    /**
     * Resolve driver ID from external references using provider-specific identifiers in the payload.
     *
     * Priority chain:
     * 1. payload.driver.id (both formats at root)
     * 2. payload.data.conditions.0.details.panicButton.driver.id (AlertIncident nested)
     */
    private function resolveDriverId(?int $providerId, ?int $teamId, array $payload): ?int
    {
        if (! $providerId || ! $teamId) {
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

        if ($reference === null) {
            return null;
        }

        // Same platform-wide unique key as the asset references above: verify
        // the driver belongs to the tenant that owns the event.
        $belongs = Driver::query()
            ->whereKey($reference->driver_id)
            ->where('team_id', $teamId)
            ->exists();

        return $belongs ? $reference->driver_id : null;
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
            'is_resolved' => Arr::get($payload, 'data.isResolved') ?? $this->resolveFeedDismissal($payload),
            'external_resolved_at' => Arr::get($payload, 'data.resolvedAtTime') ?? $this->resolveFeedResolvedAt($payload),
            'event_state' => Arr::get($payload, 'eventState'),
            'raw_conditions' => Arr::get($payload, 'data.conditions'),
            'raw_behavior_labels' => Arr::get($payload, 'behaviorLabels'),
        ];
    }

    /**
     * Safety events from the polling feed carry their lifecycle in
     * `eventState`; a dismissal at the provider is the feed's equivalent of an
     * AlertIncident `isResolved` update, so it flows through the same external
     * resolution mechanism. Non-dismissed states return null (not false) so
     * webhook events without a feed state keep their payload untouched.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveFeedDismissal(array $payload): ?bool
    {
        return Arr::get($payload, 'eventState') === 'dismissed' ? true : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveFeedResolvedAt(array $payload): ?string
    {
        if (Arr::get($payload, 'eventState') !== 'dismissed') {
            return null;
        }

        $updatedAt = Arr::get($payload, 'updatedAtTime');

        return is_string($updatedAt) ? $updatedAt : null;
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
