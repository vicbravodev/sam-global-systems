<?php

namespace App\Domains\Normalization\Actions;

use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;

/**
 * ¿Es (o sería) una emergencia —pánico, colisión, vuelco— este raw event?
 * Sirve para priorizar rescates y decidir si el fallo se avisa al tenant,
 * también cuando el evento nunca llegó a normalizarse.
 *
 * Orden: el evento normalizado si existe; si no, el código interno
 * (`event_type_raw` = código del catálogo); si no, las reglas de mapeo activas
 * del proveedor para ese tipo. Ante reglas con condiciones, basta con que UNA
 * mapee a emergencia: aquí es mejor avisar de más que perder un pánico.
 */
class ClassifyRawEventEmergency
{
    /** @var array<string, array{emergency: bool, event_type_code: ?string}> */
    private array $memo = [];

    /**
     * @return array{emergency: bool, event_type_code: ?string, normalized_event_id: ?int, asset_id: ?int}
     */
    public function execute(RawEvent $rawEvent): array
    {
        $normalized = NormalizedEvent::query()
            ->where('team_id', $rawEvent->team_id)
            ->where('raw_event_id', $rawEvent->id)
            ->with(['eventType:id,code,category_id', 'eventType.category:id,code', 'eventCategory:id,code'])
            ->first();

        if ($normalized !== null) {
            return [
                ...$this->fromNormalized($normalized),
                'normalized_event_id' => (int) $normalized->id,
                'asset_id' => $normalized->asset_id !== null ? (int) $normalized->asset_id : null,
            ];
        }

        $key = ($rawEvent->provider_id ?? 'internal').'|'.($rawEvent->event_type_raw ?? '');

        return [
            ...($this->memo[$key] ??= $this->fromRaw($rawEvent)),
            'normalized_event_id' => null,
            'asset_id' => null,
        ];
    }

    /**
     * @return array{emergency: bool, event_type_code: ?string}
     */
    public function fromNormalized(NormalizedEvent $normalized): array
    {
        $typeCode = $normalized->eventType?->code;
        $categoryCode = $normalized->eventCategory?->code ?? $normalized->eventType?->category?->code;

        return [
            'emergency' => NormalizeRawEvent::isEmergencyCode($categoryCode, $typeCode),
            'event_type_code' => $typeCode,
        ];
    }

    /**
     * @return array{emergency: bool, event_type_code: ?string}
     */
    private function fromRaw(RawEvent $rawEvent): array
    {
        $rawType = (string) ($rawEvent->event_type_raw ?? '');

        if ($rawType === '') {
            return ['emergency' => false, 'event_type_code' => null];
        }

        if ($rawEvent->provider_id === null) {
            $type = EventType::query()->with('category:id,code')->where('code', $rawType)->first();

            return [
                'emergency' => NormalizeRawEvent::isEmergencyCode($type?->category?->code, $type?->code ?? $rawType),
                'event_type_code' => $type?->code,
            ];
        }

        $rules = EventMappingRule::query()
            ->active()
            ->where('provider_id', $rawEvent->provider_id)
            ->where('external_event_type', $rawType)
            ->with(['mappedEventType:id,code,category_id', 'mappedEventType.category:id,code', 'mappedCategory:id,code'])
            ->orderByDesc('priority')
            ->get();

        foreach ($rules as $rule) {
            $categoryCode = $rule->mappedCategory?->code ?? $rule->mappedEventType?->category?->code;

            if (NormalizeRawEvent::isEmergencyCode($categoryCode, $rule->mappedEventType?->code)) {
                return ['emergency' => true, 'event_type_code' => $rule->mappedEventType?->code];
            }
        }

        return ['emergency' => false, 'event_type_code' => $rules->first()?->mappedEventType?->code];
    }
}
