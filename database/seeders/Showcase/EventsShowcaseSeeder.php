<?php

namespace Database\Seeders\Showcase;

use App\Domains\Assets\Models\Asset;
use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\EventType;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseEventCatalog;
use Database\Seeders\Showcase\Support\ShowcaseGeo;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Support\Facades\DB;

/**
 * Historial de eventos de la flota: raw + normalizados, día por día, con
 * patrón de horario operativo (picos de salida y regreso), menos actividad
 * en fin de semana y un subconjunto de unidades "problemáticas" que
 * concentra la mitad de los eventos.
 *
 * Los eventos simulados entran por fuentes propias del showcase
 * (`event_sources.source_name = showcase-*`), así nunca se confunden con
 * los reales de Samsara y se pueden filtrar o borrar en bloque.
 *
 * Marcador / idempotencia: `raw_events.external_event_id =
 * showcase-{team}-{Ymd}-{n}`. Un día que ya tiene eventos del showcase se
 * salta entero; correr de nuevo mañana sólo añade el día nuevo.
 */
class EventsShowcaseSeeder extends ShowcaseStep
{
    /** @var array<string, EventSource> */
    private array $sources = [];

    /** @var array<string, EventType> */
    private array $types = [];

    public function run(): void
    {
        if ($this->ctx->assets->isEmpty()) {
            return;
        }

        $this->types = EventType::query()->with(['category', 'defaultSeverity'])->get()->keyBy('code')->all();
        $this->sources = [
            'alert' => $this->source('showcase-webhook', EventSourceType::Webhook),
            'safety' => $this->source('showcase-safety-feed', EventSourceType::PollingFeed),
            'internal' => $this->source('showcase-internal-monitor', EventSourceType::InternalMonitor),
        ];

        // Si el tenant ya recibe device_offline reales no se simulan más.
        $includeOffline = ! DB::table('normalized_events')
            ->join('event_types', 'event_types.id', '=', 'normalized_events.event_type_id')
            ->where('normalized_events.team_id', $this->ctx->team->id)
            ->where('event_types.code', 'device_offline')
            ->exists();
        $weights = array_filter(
            ShowcaseEventCatalog::weights($includeOffline),
            fn (string $code) => isset($this->types[$code]),
            ARRAY_FILTER_USE_KEY,
        );

        $existingDays = $this->existingDays();
        $assets = $this->ctx->assets->filter(fn (Asset $a) => $a->status?->value !== 'inactive')->values();
        $risky = $this->ctx->random('risky')->sample($assets->all(), max(1, (int) round($assets->count() * 0.2)));
        $perDay = $this->ctx->light ? 4 : (int) max(8, min(40, round($assets->count() * 0.12)));
        $created = 0;

        for ($day = $this->ctx->startDay(); $day->lessThanOrEqualTo($this->ctx->now); $day = $day->addDay()) {
            if (isset($existingDays[$day->format('Ymd')])) {
                continue;
            }

            $created += $this->seedDay($day, $perDay, $weights, $assets->all(), $risky);
        }

        $this->ctx->info("Eventos simulados nuevos: {$created}.");
    }

    private function source(string $name, EventSourceType $type): EventSource
    {
        return EventSource::query()->firstOrCreate(
            ['team_id' => $this->ctx->team->id, 'source_name' => $name],
            [
                'provider_id' => $this->ctx->provider?->id,
                'tenant_integration_id' => $this->ctx->integration?->id,
                'source_type' => $type,
                'status' => EventSourceStatus::Active,
                'config_json' => ['showcase' => true, 'note' => 'Eventos simulados por sam:showcase'],
            ],
        );
    }

    /**
     * @return array<string, true>
     */
    private function existingDays(): array
    {
        $prefix = "showcase-{$this->ctx->team->id}-";

        return RawEvent::query()
            ->where('team_id', $this->ctx->team->id)
            ->whereIn('event_source_id', array_map(fn (EventSource $s) => $s->id, $this->sources))
            ->where('external_event_id', 'like', $prefix.'%')
            ->pluck('external_event_id')
            ->mapWithKeys(fn (string $id) => [substr($id, strlen($prefix), 8) => true])
            ->all();
    }

    /**
     * @param  array<string, float>  $weights
     * @param  array<int, Asset>  $assets
     * @param  array<int, Asset>  $risky
     */
    private function seedDay(CarbonImmutable $day, int $perDay, array $weights, array $assets, array $risky): int
    {
        $ymd = $day->format('Ymd');
        $random = $this->ctx->random('events', $ymd);
        $weekdayFactor = match ((int) $day->dayOfWeekIso) {
            6 => 0.6,
            7 => 0.35,
            default => 1.0,
        };
        $count = (int) round($perDay * $weekdayFactor * $random->float(0.75, 1.25));

        $raw = [];
        $plans = [];

        $slots = [];

        for ($n = 1; $n <= $count; $n++) {
            $slots[$n] = [$random->operationalMoment($day), null];
        }

        // "En vivo": el día de hoy siempre trae emergencias de los últimos
        // minutos, para que la bandeja y el dashboard tengan casos abiertos.
        if (! $this->ctx->light && $day->isSameDay($this->ctx->now)) {
            foreach ([6 => 'panic_button', 28 => 'collision', 75 => 'panic_button'] as $minutes => $live) {
                $slots[900 + $minutes] = [$this->ctx->now->subMinutes($minutes), isset($weights[$live]) ? $live : null];
            }
        }

        foreach ($slots as $n => [$at, $forcedType]) {
            if ($at->greaterThan($this->ctx->now) || $at->lessThan($day)) {
                continue;
            }

            $typeCode = $forcedType ?? $random->weighted($weights);
            $asset = $random->chance(0.5) ? $random->pick($risky) : $random->pick($assets);
            $driver = FleetShowcaseSeeder::driverFor($this->ctx, $asset, $random);
            $unmapped = $random->chance(0.012);
            [$homeLabel, $homeLat, $homeLng] = $this->ctx->homes[$asset->id] ?? ShowcaseGeo::place($random);
            [$place, $placeLat, $placeLng] = $random->chance(0.6) ? [$homeLabel, $homeLat, $homeLng] : ShowcaseGeo::place($random);
            [$lat, $lng] = ShowcaseGeo::jitter($random, $placeLat, $placeLng, 2500);
            $speed = match ($typeCode) {
                'severe_speeding' => $random->float(105, 128, 1),
                'speeding' => $random->float(88, 104, 1),
                'vehicle_idle', 'suspicious_stop', 'unsafe_parking', 'device_offline' => 0.0,
                default => $random->float(18, 92, 1),
            };
            $externalId = sprintf('showcase-%d-%s-%03d', $this->ctx->team->id, $ymd, $n);
            $who = [
                'id' => (string) ($asset->external_primary_id ?? $asset->id),
                'name' => $asset->name,
                'driver_id' => $driver?->external_primary_id ?? ($driver ? (string) $driver->id : null),
                'driver_name' => $driver?->full_name,
                'lat' => $lat,
                'lng' => $lng,
                'address' => $place,
                'speed' => $speed,
            ];
            $shape = ShowcaseEventCatalog::sourceShape($typeCode);
            $payload = ShowcaseEventCatalog::rawPayload($typeCode, $externalId, $at, $who, $random);
            $label = $unmapped ? 'EdgeBlindSpotAlert' : ShowcaseEventCatalog::TYPES[$typeCode][1];

            if ($unmapped) {
                $payload['behaviorLabels'] = [['label' => $label, 'source' => 'automated', 'name' => $label]];
            }

            $received = $at->addSeconds($shape === 'safety' ? $random->int(40, 600) : $random->int(1, 6));

            $raw[] = [
                'team_id' => $this->ctx->team->id,
                'event_source_id' => $this->sources[$shape]->id,
                'provider_id' => $this->ctx->provider?->id,
                'external_event_id' => $externalId,
                'event_type_raw' => $label,
                'payload_json' => $payload,
                'headers_json' => $shape === 'alert' ? ['x-samsara-event-type' => 'AlertIncident'] : null,
                'received_at' => $received,
                'occurred_at' => $at,
                'deduplication_key' => 'showcase:'.$externalId,
                'status' => 'processed',
                'checksum' => hash('sha256', $externalId),
                'processing_attempts' => 1,
                'last_processing_attempt_at' => $received,
                'created_at' => $received,
                'updated_at' => $received,
            ];
            $plans[$externalId] = [
                'type' => $unmapped ? 'unmapped' : $typeCode,
                'label' => $label,
                'asset' => $asset,
                'driver' => $driver,
                'at' => $at,
                'received' => $received,
                'who' => $who,
                'random' => ShowcaseRandom::forKey($externalId),
            ];
        }

        if ($raw === []) {
            return 0;
        }

        $this->bulkInsert('raw_events', $raw, timestamps: false);

        $rawIds = RawEvent::query()
            ->where('team_id', $this->ctx->team->id)
            ->whereIn('external_event_id', array_keys($plans))
            ->pluck('id', 'external_event_id');

        $normalized = [];

        foreach ($plans as $externalId => $plan) {
            $type = $this->types[$plan['type']] ?? $this->types['unmapped'] ?? null;

            if ($type === null || ! isset($rawIds[$externalId])) {
                continue;
            }

            $normalized[] = $this->normalizedRow((int) $rawIds[$externalId], $type, $plan);
        }

        $this->bulkInsert('normalized_events', $normalized, timestamps: false);

        return count($normalized);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function normalizedRow(int $rawId, EventType $type, array $plan): array
    {
        /** @var ShowcaseRandom $random */
        $random = $plan['random'];
        $who = $plan['who'];
        $severity = $type->defaultSeverity;
        $status = match (true) {
            $type->code === 'unmapped' => 'unmapped',
            $random->chance(0.004) => 'failed',
            default => 'enriched',
        };
        $processed = $plan['received']->addSeconds($random->int(1, 4));
        $location = ['latitude' => $who['lat'], 'longitude' => $who['lng'], 'formatted_location' => $who['address']];

        return [
            'raw_event_id' => $rawId,
            'team_id' => $this->ctx->team->id,
            'provider_id' => $this->ctx->provider?->id,
            'asset_id' => $plan['asset']->id,
            'driver_id' => $plan['driver']?->id,
            'event_type_id' => $type->id,
            'event_category_id' => $type->category_id,
            'event_severity_id' => $severity?->id ?? $type->default_severity_id,
            'occurred_at' => $plan['at'],
            'processed_at' => $processed,
            'payload_normalized_json' => [
                'location' => $location,
                'description' => $type->name,
                'event_state' => $plan['type'] === 'panic_button' ? null : 'needsReview',
                'is_resolved' => false,
                'occurred_at' => $plan['at']->toIso8601String(),
                'severity_code' => $severity?->code,
                'event_type_code' => $type->code,
                'external_event_type' => $plan['label'],
                'raw_behavior_labels' => [$plan['label']],
                'speed_kph' => $who['speed'],
                'speed_metadata' => null,
                'external_resolved_at' => null,
                'showcase' => true,
            ],
            'context_json' => [
                'location' => $location,
                'weather' => $random->pick(['Despejado, 31 °C', 'Nublado, 24 °C', 'Lluvia ligera, 19 °C', 'Despejado, 36 °C', 'Neblina, 14 °C']),
                'traffic' => $random->pick(['Fluido', 'Moderado', 'Denso', 'Fluido']),
                'driver_risk' => $random->int(8, 88),
                'geofence_status' => $random->chance(0.3) ? 'Dentro de geocerca' : 'Fuera de geocercas conocidas',
                'driving_hours' => sprintf('%d h %02d min', $random->int(0, 10), $random->int(0, 59)),
            ],
            'status' => $status,
            'created_at' => $processed,
            'updated_at' => $processed,
        ];
    }
}
