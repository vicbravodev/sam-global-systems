<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Enums\AssetUnresolvedReason;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Enums\SamsaraAlertTrigger;
use App\Domains\Normalization\Events\EventNormalized;
use App\Domains\Normalization\Events\EventUnmapped;
use App\Domains\Normalization\Events\UnmonitoredAssetEmergencyReceived;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\Conditions\FlatConditionMatcher;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use Illuminate\Support\Arr;
use LogicException;

class NormalizeRawEvent
{
    public const string EMERGENCY_CATEGORY = 'emergency';

    /** @var array<int, string> */
    public const array EMERGENCY_EVENT_TYPES = ['panic_button', 'collision', 'rollover_protection'];

    /**
     * Where a provider payload carries the unit, in priority order. The last
     * path walks every AlertIncident condition and trigger detail.
     *
     * @var list<string>
     */
    private const array ASSET_ID_PATHS = ['asset.id', 'vehicle.id', 'vehicleId', 'data.conditions.*.details.*.vehicle.id'];

    /** @var list<string> */
    private const array DRIVER_ID_PATHS = ['driver.id', 'data.conditions.*.details.*.driver.id'];

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

        // provider_id es FK a providers.id (secuencia desde 1): nunca es 0.
        $rule = $providerId !== null
            ? $this->mapExternalEventType->execute($providerId, $externalEventType, $payload)
            : null;

        if ($rule === null) {
            // Internal monitor events (Roadmap V2-C1) carry no provider and
            // need no mapping rule: their `event_type_raw` IS the event type
            // code and the asset comes pre-resolved in the payload.
            $internalType = $this->resolveInternalEventType($rawEvent, $payload);

            if ($internalType !== null) {
                SystemLog::ok('normalization.internal.resolved', input: [
                    'raw_event_id' => $rawEvent->id,
                    'event_type_code' => $internalType->code,
                ]);

                return $this->createInternalNormalizedEvent($rawEvent, $internalType, $payload);
            }

            if ($providerId === null) {
                SystemLog::skipped('normalization.type.unmapped', reason: 'no_provider', input: [
                    'raw_event_id' => $rawEvent->id,
                    'external_event_type' => $externalEventType,
                ]);
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

        $code = $rawEvent->event_type_raw ?? '';

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
        ['asset_id' => $assetId, 'unresolved_reason' => $unresolvedReason] = $this->resolveInternalAssetId($rawEvent, $payload);

        if ($this->assetIsSwitchedOff($assetId)) {
            // La ruta interna descarta incluso emergencias (comportamiento vigente).
            $this->logDiscarded($rawEvent, $assetId, $eventType, $eventType->category, emergencyExemptionApplies: false);
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
                'payload_normalized_json' => [
                    ...$this->buildNormalizedPayload($rawEvent, $eventType, $severity, $payload),
                    ...self::unresolvedAssetMarker($unresolvedReason),
                ],
                'status' => NormalizedEventStatus::Normalized,
            ],
        );

        $rawEvent->markAsProcessed();

        PipelineTrace::add(['normalized_event_id' => $normalizedEvent->id]);

        $this->logNormalized($rawEvent, $normalizedEvent, 'internal', $eventType, $eventType->category, $severity, false);

        EventNormalized::dispatch($normalizedEvent);

        return $normalizedEvent;
    }

    /**
     * The internal asset id is only honored when the asset belongs to the raw
     * event's tenant — a forged payload can never bind a foreign asset.
     *
     * @param  array<string, mixed>  $payload
     * @return array{asset_id: int|null, unresolved_reason: AssetUnresolvedReason|null}
     */
    private function resolveInternalAssetId(RawEvent $rawEvent, array $payload): array
    {
        $assetId = (int) Arr::get($payload, 'internal.asset_id');

        $belongs = Asset::query()
            ->whereKey($assetId)
            ->where('team_id', $rawEvent->team_id)
            ->exists();

        if ($belongs) {
            return ['asset_id' => $assetId, 'unresolved_reason' => null];
        }

        $rejection = match ($this->classifyRejection(Asset::class, $assetId, $rawEvent->team_id)) {
            'foreign' => 'cross_tenant_internal_asset',
            'trashed' => 'internal_asset_trashed',
            'missing' => 'internal_asset_missing',
        };

        SystemLog::degraded('normalization.asset.rejected', reason: $rejection, input: [
            'raw_event_id' => $rawEvent->id,
        ], calc: ['rejection' => $rejection]);

        return [
            'asset_id' => null,
            'unresolved_reason' => $rejection === 'cross_tenant_internal_asset'
                ? AssetUnresolvedReason::ForeignAssetRejected
                : AssetUnresolvedReason::UnknownExternalId,
        ];
    }

    /**
     * Marca en el payload normalizado por qué el evento quedó sin unidad; el
     * contexto la convierte en la señal `asset_unresolved`. Nunca guarda el
     * id externo ni el activo ajeno, sólo la razón.
     *
     * @return array{asset_unresolved_reason?: string}
     */
    private static function unresolvedAssetMarker(?AssetUnresolvedReason $reason): array
    {
        return $reason === null ? [] : ['asset_unresolved_reason' => $reason->value];
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
                // El payload crudo, más lo que el resto del pipeline necesita
                // decidir sin releerlo (p. ej. el gate de IA con las alertas
                // del proveedor reconocidas).
                'payload_normalized_json' => [
                    ...($rawEvent->payload_json ?? []),
                    'external_event_type' => $externalEventType,
                    'provider_trigger_ids' => SamsaraAlertTrigger::fromPayload($rawEvent->payload_json ?? []),
                ],
                'status' => NormalizedEventStatus::Unmapped,
            ],
        );

        $rawEvent->markAsProcessed();

        PipelineTrace::add(['normalized_event_id' => $normalizedEvent->id]);

        $this->logNormalized($rawEvent, $normalizedEvent, 'unmapped', null, null, null, false);

        EventUnmapped::dispatch($rawEvent, $externalEventType, $providerId ?? 0);

        return $normalizedEvent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createNormalizedEvent(
        RawEvent $rawEvent,
        EventMappingRule $rule,
        array $payload,
    ): ?NormalizedEvent {
        // mapped_event_type_id y event_types.category_id son FK NOT NULL con
        // cascade: si no cargan, la regla está rota y debe fallar con claridad.
        $eventType = $rule->mappedEventType
            ?? throw new LogicException("EventMappingRule {$rule->id} sin EventType.");
        $severity = $this->resolveEventSeverity->execute($rule, $eventType);
        // mapped_category_id es nullOnDelete: sin categoría propia, la del tipo.
        $category = ($rule->mapped_category_id !== null ? $rule->mappedCategory : null)
            ?? $eventType->category
            ?? throw new LogicException("EventType {$eventType->id} sin EventCategory.");

        ['asset_id' => $assetId, 'unresolved_reason' => $unresolvedReason] = $this->resolveAssetId($rawEvent->provider_id, $rawEvent->team_id, $payload, $rawEvent->id);

        // Una emergencia (pánico, colisión, vuelco) SIEMPRE se atiende, esté o
        // no vigilada la unidad (decisión 2026-09-28): la prioridad es la
        // persona. Ese día la unidad se cobra como extra (tracto-día + recargo)
        // y se avisa al admin. Todo lo demás de una unidad apagada se descarta.
        $unmonitored = $this->assetIsSwitchedOff($assetId);

        if ($unmonitored && ! $this->isEmergency($eventType, $category)) {
            $this->logDiscarded($rawEvent, $assetId, $eventType, $category, emergencyExemptionApplies: true);
            $this->discard($rawEvent);

            return null;
        }

        $driverId = $this->resolveDriverId($rawEvent->provider_id, $rawEvent->team_id, $payload, $rawEvent->id);

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
                    ...$this->buildNormalizedPayload($rawEvent, $eventType, $severity, $payload, $rule),
                    ...($unmonitored ? ['unmonitored_asset' => true] : []),
                    ...self::unresolvedAssetMarker($unresolvedReason),
                ],
                'status' => NormalizedEventStatus::Normalized,
            ],
        );

        $rawEvent->markAsProcessed();

        PipelineTrace::add(['normalized_event_id' => $normalizedEvent->id]);

        if ($unmonitored) {
            SystemLog::ok(
                'normalization.event.emergency_unmonitored_passed',
                input: [
                    'raw_event_id' => $rawEvent->id,
                    'asset_id' => $assetId,
                    'event_type_code' => $eventType->code,
                    'category_code' => $category?->code,
                ],
                calc: ['is_emergency' => true],
                // Sólo se afirma el despacho: el cargo real lo decide billing
                // (ChargeUnmonitoredEmergency, idempotente por activo y día local).
                result: [
                    'normalized_event_id' => $normalizedEvent->id,
                    'extra_charge_dispatched' => true,
                    'charge_scope' => 'asset_local_day',
                ],
            );
        }

        $this->logNormalized($rawEvent, $normalizedEvent, 'mapped', $eventType, $category, $severity, $unmonitored);

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

    /**
     * `emergency_exemption_applies` records whether the route lets emergencies
     * through an unmonitored asset: the mapped route does, the internal route
     * discards even emergencies.
     */
    private function logDiscarded(RawEvent $rawEvent, ?int $assetId, EventType $eventType, ?EventCategory $category, bool $emergencyExemptionApplies): void
    {
        $monitoringState = $assetId === null ? null : Asset::query()
            ->whereKey($assetId)
            ->where('team_id', $rawEvent->team_id)
            ->value('monitoring_state');

        SystemLog::skipped(
            'normalization.event.discarded',
            reason: 'asset_not_monitored',
            input: [
                'raw_event_id' => $rawEvent->id,
                'asset_id' => $assetId,
                'event_type_code' => $eventType->code,
                'category_code' => $category?->code,
                'monitoring_state' => $monitoringState instanceof \BackedEnum ? $monitoringState->value : $monitoringState,
            ],
            calc: [
                'is_emergency' => self::isEmergencyCode($category?->code, $eventType->code),
                'emergency_exemption_applies' => $emergencyExemptionApplies,
            ],
        );
    }

    private function logNormalized(
        RawEvent $rawEvent,
        NormalizedEvent $normalizedEvent,
        string $route,
        ?EventType $eventType,
        ?EventCategory $category,
        ?EventSeverity $severity,
        bool $unmonitored,
    ): void {
        SystemLog::ok(
            'normalization.event.normalized',
            input: ['raw_event_id' => $rawEvent->id],
            result: [
                'normalized_event_id' => $normalizedEvent->id,
                'route' => $route,
                'event_type_code' => $eventType?->code ?? $normalizedEvent->eventType?->code,
                'category_code' => $category?->code ?? $normalizedEvent->eventCategory?->code,
                'severity_code' => $severity?->code ?? $normalizedEvent->eventSeverity?->code,
                'asset_id' => $normalizedEvent->asset_id,
                'driver_id' => $normalizedEvent->driver_id,
                'unmonitored_asset' => $unmonitored,
            ],
        );
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
     * 4. payload.data.conditions.*.details.*.vehicle.id (AlertIncident: any
     *    condition, any trigger — panicButton, tamperingDetected, harshEvent…)
     *
     * With no asset it also says why (`AssetUnresolvedReason`): no id in the
     * payload, an id the tenant does not know (or whose asset it deleted), or
     * a reference owned by another tenant.
     *
     * @param  array<string, mixed>  $payload
     * @return array{asset_id: int|null, unresolved_reason: AssetUnresolvedReason|null}
     */
    private function resolveAssetId(?int $providerId, ?int $teamId, array $payload, int $rawEventId): array
    {
        $path = null;
        $referenceFound = false;
        $rejection = null;
        $assetId = null;

        // Ids de secuencia (FK): nunca son 0, así que `!== null` equivale al truthy.
        if ($providerId !== null && $teamId !== null) {
            ['path' => $path, 'value' => $externalId] = self::firstPayloadId($payload, self::ASSET_ID_PATHS);

            $reference = $path === null ? null : AssetExternalReference::query()
                ->where('provider_id', $providerId)
                ->where('external_id', $externalId)
                ->first();

            if ($reference !== null) {
                $referenceFound = true;

                // (provider_id, external_id) is unique platform-wide, so a payload id
                // can point at another tenant's asset. Isolation must not depend on
                // how the provider allocates its identifiers.
                $belongs = Asset::query()
                    ->whereKey($reference->asset_id)
                    ->where('team_id', $teamId)
                    ->exists();

                $assetId = $belongs ? $reference->asset_id : null;
                $rejection = $belongs ? null : $this->referenceRejection('asset', Asset::class, $reference->asset_id, $teamId);
            }
        }

        $this->logReference('asset', 'asset_path_used', $rawEventId, $path, $referenceFound, $rejection, $assetId);

        return [
            'asset_id' => $assetId,
            'unresolved_reason' => match (true) {
                $assetId !== null => null,
                $path === null => AssetUnresolvedReason::NoVehicleInPayload,
                $rejection === 'cross_tenant_reference' => AssetUnresolvedReason::ForeignAssetRejected,
                default => AssetUnresolvedReason::UnknownExternalId,
            },
        ];
    }

    /**
     * One line per resolution. The foreign id of a rejected reference is never
     * logged: `cross_tenant_rejected` is only a flag, and `rejection` says why
     * the reference did not resolve (the tenant's own trashed row is not a
     * cross-tenant alarm). A payload with no id at all is routine: debug.
     */
    private function logReference(string $kind, string $pathKey, int $rawEventId, ?string $path, bool $referenceFound, ?string $rejection, ?int $resolvedId): void
    {
        $input = ['raw_event_id' => $rawEventId];
        $calc = [
            $pathKey => $path,
            'reference_found' => $referenceFound,
            'cross_tenant_rejected' => $rejection === 'cross_tenant_reference',
            'rejection' => $rejection,
        ];
        $result = ["{$kind}_id" => $resolvedId];

        if ($rejection !== null) {
            SystemLog::degraded("normalization.{$kind}.resolved", reason: $rejection, input: $input, calc: $calc, result: $result);

            return;
        }

        SystemLog::ok("normalization.{$kind}.resolved", input: $input, calc: $calc, result: $result, debug: $path === null);
    }

    /**
     * @param  class-string<Asset|Driver>  $model
     */
    private function referenceRejection(string $kind, string $model, int $id, ?int $teamId): string
    {
        return match ($this->classifyRejection($model, $id, $teamId)) {
            'foreign' => 'cross_tenant_reference',
            'trashed' => "referenced_{$kind}_trashed",
            'missing' => "referenced_{$kind}_missing",
        };
    }

    /**
     * Why a referenced row did not resolve for the event's tenant. Runs only
     * after a rejection and only to classify it: one query by primary key that
     * bypasses the tenant scope and soft deletes, selecting `team_id` and
     * `deleted_at` alone. Neither value is ever logged, and the resolved id is
     * decided before (and independently of) this query.
     *
     * @param  class-string<Asset|Driver>  $model
     * @return 'foreign'|'trashed'|'missing'
     */
    private function classifyRejection(string $model, int $id, ?int $teamId): string
    {
        $row = $model::query()
            ->withoutGlobalScope('tenant')
            ->withTrashed()
            ->whereKey($id)
            ->first(['team_id', 'deleted_at']);

        if ($row === null) {
            return 'missing';
        }

        if ($row->team_id !== (int) $teamId) {
            return 'foreign';
        }

        return $row->deleted_at !== null ? 'trashed' : 'missing';
    }

    /**
     * Resolve driver ID from external references using provider-specific identifiers in the payload.
     *
     * Priority chain:
     * 1. payload.driver.id (both formats at root)
     * 2. payload.data.conditions.*.details.*.driver.id (AlertIncident: any condition, any trigger)
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveDriverId(?int $providerId, ?int $teamId, array $payload, int $rawEventId): ?int
    {
        $path = null;
        $referenceFound = false;
        $rejection = null;
        $driverId = null;

        // Ids de secuencia (FK): nunca son 0, así que `!== null` equivale al truthy.
        if ($providerId !== null && $teamId !== null) {
            ['path' => $path, 'value' => $externalId] = self::firstPayloadId($payload, self::DRIVER_ID_PATHS);

            $reference = $path === null ? null : DriverExternalReference::query()
                ->where('provider_id', $providerId)
                ->where('external_id', $externalId)
                ->first();

            if ($reference !== null) {
                $referenceFound = true;

                // Same platform-wide unique key as the asset references above: verify
                // the driver belongs to the tenant that owns the event.
                $belongs = Driver::query()
                    ->whereKey($reference->driver_id)
                    ->where('team_id', $teamId)
                    ->exists();

                $driverId = $belongs ? $reference->driver_id : null;
                $rejection = $belongs ? null : $this->referenceRejection('driver', Driver::class, $reference->driver_id, $teamId);
            }
        }

        $this->logReference('driver', 'driver_path_used', $rawEventId, $path, $referenceFound, $rejection, $driverId);

        return $driverId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildNormalizedPayload(RawEvent $rawEvent, EventType $eventType, EventSeverity $severity, array $payload, ?EventMappingRule $rule = null): array
    {
        return [
            'event_type_code' => $eventType->code,
            'severity_code' => $severity->code,
            'external_event_type' => $rawEvent->event_type_raw,
            'description' => Arr::get($this->matchedCondition($payload, $rule) ?? [], 'description')
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
            'provider_trigger_ids' => SamsaraAlertTrigger::fromPayload($payload),
            'raw_behavior_labels' => Arr::get($payload, 'behaviorLabels'),
        ];
    }

    /**
     * The AlertIncident condition the mapping rule matched: the one whose
     * `triggerId` the rule asked for (`data.conditions.*.triggerId`), else the
     * first. Its description is what the operator reads.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function matchedCondition(array $payload, ?EventMappingRule $rule): ?array
    {
        $conditions = Arr::get($payload, 'data.conditions');

        if (! is_array($conditions) || $conditions === []) {
            return null;
        }

        $wanted = $rule?->external_conditions_json['data.conditions.*.triggerId'] ?? null;

        if ($wanted !== null) {
            foreach ($conditions as $condition) {
                if (is_array($condition) && FlatConditionMatcher::equals($condition['triggerId'] ?? null, $wanted)) {
                    return $condition;
                }
            }
        }

        $first = reset($conditions);

        return is_array($first) ? $first : null;
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
        $id = EventType::where('code', 'unmapped')->value('id');

        if ($id === null) {
            SystemLog::degraded('normalization.catalog.fallback_used', reason: 'catalog_row_missing', input: [
                'expected_code' => 'unmapped',
                'table' => 'event_types',
            ]);
        }

        return $id ?? EventType::query()->value('id');
    }

    private function getUnmappedCategoryId(): int
    {
        $id = EventCategory::where('code', 'operational')->value('id');

        if ($id === null) {
            SystemLog::degraded('normalization.catalog.fallback_used', reason: 'catalog_row_missing', input: [
                'expected_code' => 'operational',
                'table' => 'event_categories',
            ]);
        }

        return $id ?? EventCategory::query()->value('id');
    }

    private function getUnmappedSeverityId(): int
    {
        $id = EventSeverity::where('code', 'low')->value('id');

        if ($id === null) {
            SystemLog::degraded('normalization.catalog.fallback_used', reason: 'catalog_row_missing', input: [
                'expected_code' => 'low',
                'table' => 'event_severities',
            ]);
        }

        return $id ?? EventSeverity::query()->value('id');
    }

    /**
     * First id found along `$paths`. A plain path wins with its first non-null
     * value (as `??`); a `*` path wins with the first non-null element it
     * reaches. A falsy value ('0', '', 0, false…) means "no id" and stops the
     * search, as it always did. `path` is the candidate as written (generic,
     * loggable), never the id.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     * @return array{path: string|null, value: string|null}
     */
    private static function firstPayloadId(array $payload, array $paths): array
    {
        foreach ($paths as $candidate) {
            $value = str_contains($candidate, '*')
                ? collect((array) data_get($payload, $candidate))->first(fn (mixed $item): bool => $item !== null)
                : Arr::get($payload, $candidate);

            if ($value === null) {
                continue;
            }

            if (self::isFalsyPayloadId($value) || ! is_scalar($value)) {
                return ['path' => null, 'value' => null];
            }

            return ['path' => $candidate, 'value' => (string) $value];
        }

        return ['path' => null, 'value' => null];
    }

    /**
     * Truthiness de PHP sobre un id del payload del proveedor (mixed): `'0'`,
     * `''`, `0`, `0.0`, `false` y `[]` cuentan como "sin id".
     */
    private static function isFalsyPayloadId(mixed $value): bool
    {
        return in_array($value, [null, false, 0, 0.0, '', '0', []], true);
    }
}
