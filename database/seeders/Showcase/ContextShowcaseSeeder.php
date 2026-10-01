<?php

namespace Database\Seeders\Showcase;

use App\Domains\Context\Models\Geofence;
use App\Domains\Normalization\Models\NormalizedEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\EventScenario;
use Database\Seeders\Showcase\Support\ShowcaseEvents;
use Database\Seeders\Showcase\Support\ShowcaseGeo;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contexto operativo de cada evento: geocercas del tenant, snapshot de
 * contexto (ubicación, activo, conductor, telemetría, geocercas, historial,
 * media, señales), perfil operativo e historial reciente de los eventos que
 * pasan por IA, cruces con geocercas, y la evidencia visual (fotos/clips y
 * sus solicitudes al proveedor).
 *
 * Idempotencia: sólo trabaja eventos SIN snapshot de contexto (los reales
 * ya lo traen del pipeline). La media se crea sólo para eventos sin media.
 * Las geocercas sólo si el tenant no tiene ninguna.
 */
class ContextShowcaseSeeder extends ShowcaseStep
{
    /** @var array<int, Geofence> */
    private array $geofences = [];

    public function run(): void
    {
        $this->seedGeofences();

        $events = new ShowcaseEvents($this->ctx);
        $query = $events->query()->whereNotExists(fn ($q) => $q->selectRaw('1')
            ->from('event_context_snapshots')
            ->whereColumn('event_context_snapshots.normalized_event_id', 'normalized_events.id'));

        $events->chunk($query, function (Collection $chunk) use ($events) {
            $snapshots = [];
            $profiles = [];
            $histories = [];
            $matches = [];

            foreach ($chunk as $event) {
                $scenario = $events->scenario($event);
                $random = ShowcaseRandom::forKey('context:'.$event->id);
                [$address, $lat, $lng] = $events->location($event);
                $at = CarbonImmutable::parse($event->occurred_at);
                $geofence = $this->nearestGeofence($lat, $lng);
                $flags = $this->signals($event, $scenario, $geofence !== null, $random);

                $snapshots[] = [
                    'normalized_event_id' => $event->id,
                    'team_id' => $this->ctx->team->id,
                    'asset_id' => $event->asset_id,
                    'driver_id' => $event->driver_id,
                    'event_occurred_at' => $at,
                    'context_version' => 1,
                    'location_snapshot_json' => ['source' => 'event_payload', 'latitude' => $lat, 'longitude' => $lng, 'formatted_location' => $address, 'recorded_at' => $at->toIso8601String()],
                    'asset_snapshot_json' => $event->asset !== null ? ['asset_id' => $event->asset->id, 'name' => $event->asset->name, 'code' => $event->asset->code, 'status' => $event->asset->status?->value, 'has_camera' => (bool) ($event->asset->metadata_json['has_camera'] ?? true)] : null,
                    'driver_snapshot_json' => $event->driver !== null ? ['driver_id' => $event->driver->id, 'full_name' => $event->driver->full_name, 'status' => $event->driver->status?->value] : null,
                    'telemetry_snapshot_json' => ['speed_kph' => (float) ($event->payload_normalized_json['speed_kph'] ?? 0), 'recorded_at' => $at->toIso8601String(), 'position_stale' => false],
                    'geofence_snapshot_json' => $geofence !== null ? [['code' => $geofence->code, 'name' => $geofence->name, 'category' => $geofence->category?->value, 'inside' => true]] : [],
                    'incidents_snapshot_json' => [],
                    'recent_history_snapshot_json' => ['recent_events_count' => $random->int(0, 6), 'recent_same_type_count' => $random->int(0, 3), 'nearby_safety_events_count' => $random->int(0, 4)],
                    'media_snapshot_json' => [],
                    'signals_json' => $flags,
                    'created_at' => $event->processed_at,
                    'updated_at' => $event->processed_at,
                ];

                if ($geofence !== null) {
                    $matches[] = [
                        'normalized_event_id' => $event->id,
                        'geofence_id' => $geofence->id,
                        'match_type' => match ($scenario->typeCode) {
                            'geofence_exit' => 'exit',
                            'geofence_entry' => 'entry',
                            default => 'inside',
                        },
                        'matched_at' => $at,
                        'distance_meters' => ShowcaseGeo::distanceMeters($lat, $lng, (float) $geofence->geometry_json['coordinates'][1], (float) $geofence->geometry_json['coordinates'][0]),
                        'metadata_json' => ['showcase' => true],
                    ];
                }

                if (! $scenario->evaluate) {
                    continue;
                }

                $risk = match (true) {
                    $scenario->riskScore >= 80 => 'critical',
                    $scenario->riskScore >= 60 => 'high',
                    $scenario->riskScore >= 35 => 'medium',
                    default => 'low',
                };
                $profiles[] = [
                    'normalized_event_id' => $event->id,
                    'team_id' => $this->ctx->team->id,
                    'profile_code' => $flags['outside_operating_hours'] ? 'after_hours' : 'standard_route',
                    'risk_level' => $risk,
                    'priority_score' => $scenario->riskScore,
                    'recurrence_score' => $random->float(0, 60),
                    'contextual_flags_json' => array_keys(array_filter($flags)),
                    'summary_json' => ['headline' => $this->headline($event, $address), 'geofence' => $geofence?->name],
                    'created_at' => $event->processed_at,
                    'updated_at' => $event->processed_at,
                ];
                $histories[] = [
                    'normalized_event_id' => $event->id,
                    'window_start' => $at->subHour(),
                    'window_end' => $at,
                    'recent_events_count' => $random->int(0, 8),
                    'recent_incidents_count' => $random->int(0, 2),
                    'recent_same_type_count' => $random->int(0, 3),
                    'recent_high_severity_count' => $random->int(0, 2),
                    'recent_locations_json' => [['latitude' => $lat, 'longitude' => $lng, 'recorded_at' => $at->subMinutes(10)->toIso8601String()]],
                    'recent_flags_json' => array_keys(array_filter($flags)),
                    'created_at' => $event->processed_at,
                    'updated_at' => $event->processed_at,
                ];
            }

            $this->bulkInsert('event_context_snapshots', $snapshots, timestamps: false);
            $this->bulkInsert('operational_context_profiles', $profiles, timestamps: false);
            $this->bulkInsert('event_recent_history_snapshots', $histories, timestamps: false);
            $this->bulkInsert('geofence_matches', $matches);
        });

        $this->seedMedia($events);
    }

    private function seedGeofences(): void
    {
        if (! Geofence::query()->where('team_id', $this->ctx->team->id)->exists()) {
            foreach (ShowcaseGeo::GEOFENCES as $def) {
                Geofence::query()->create([
                    'team_id' => $this->ctx->team->id,
                    'name' => $def['name'],
                    'code' => $def['code'],
                    'geofence_type' => 'zone',
                    'geometry_json' => ['type' => 'Point', 'coordinates' => [$def['lng'], $def['lat']], 'radius_meters' => $def['radius']],
                    'category' => $def['category'],
                    'is_active' => true,
                    'metadata_json' => ['showcase' => true],
                ]);
                $this->ctx->count('geofences');
            }
        }

        $this->geofences = Geofence::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Geofence $g) => ($g->geometry_json['type'] ?? null) === 'Point')
            ->values()
            ->all();
    }

    private function nearestGeofence(float $lat, float $lng): ?Geofence
    {
        foreach ($this->geofences as $geofence) {
            [$gLng, $gLat] = $geofence->geometry_json['coordinates'];
            $radius = (int) ($geofence->geometry_json['radius_meters'] ?? 500);

            if (ShowcaseGeo::distanceMeters($lat, $lng, (float) $gLat, (float) $gLng) <= $radius) {
                return $geofence;
            }
        }

        return null;
    }

    /**
     * Señales de contexto con los mismos nombres que produce el pipeline real.
     *
     * @return array<string, bool>
     */
    private function signals(NormalizedEvent $event, EventScenario $scenario, bool $inGeofence, ShowcaseRandom $random): array
    {
        $hour = (int) CarbonImmutable::parse($event->occurred_at)->format('G');
        $stopped = (float) ($event->payload_normalized_json['speed_kph'] ?? 0) < 1;

        return [
            'asset_in_motion' => ! $stopped,
            'asset_recently_stopped' => $stopped,
            'outside_operating_hours' => $hour < 6 || $hour >= 22,
            'is_in_sensitive_geofence' => $inGeofence && $random->chance(0.4),
            'parked_at_base' => $inGeofence && $stopped && $random->chance(0.5),
            'has_visual_evidence' => $scenario->withMedia,
            'video_pending' => $scenario->withMedia && $random->chance(0.3),
            'camera_unavailable' => ! $scenario->withMedia && $random->chance(0.1),
            'harsh_driving_near_event' => $random->chance(0.2),
            'nearby_safety_activity' => $random->chance(0.3),
            'driver_has_recent_risk_events' => $random->chance(0.35),
            'same_type_recent_recurrence' => $random->chance(0.25),
            'repeated_panic_24h' => $scenario->typeCode === 'panic_button' && $random->chance(0.2),
            'gps_lost_in_motion' => $scenario->typeCode === 'tampering',
            'external_resolved' => false,
        ];
    }

    private function headline(NormalizedEvent $event, string $address): string
    {
        return sprintf('%s · %s · %s', $event->eventType?->name ?? 'Evento', $event->asset?->name ?? 'Unidad', $address);
    }

    /**
     * Fotos de cabina/camino y clip para los eventos con evidencia visual,
     * más la solicitud de video al proveedor (pendiente si es reciente,
     * completada o expirada si es vieja: la SD de la cámara se sobrescribe).
     */
    private function seedMedia(ShowcaseEvents $events): void
    {
        $query = $events->query()
            ->whereHas('eventCategory', fn ($q) => $q->whereNotIn('code', $events->skipCategories()))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('event_media_contexts')
                ->whereColumn('event_media_contexts.normalized_event_id', 'normalized_events.id'));

        $events->chunk($query, function (Collection $chunk) use ($events) {
            $media = [];
            $requests = [];

            foreach ($chunk as $event) {
                $scenario = $events->scenario($event);

                if (! $scenario->withMedia) {
                    continue;
                }

                $random = ShowcaseRandom::forKey('media:'.$event->id);
                $at = CarbonImmutable::parse($event->occurred_at);
                $ageHours = $at->diffInHours($this->ctx->now);
                $base = [
                    'team_id' => $this->ctx->team->id,
                    'normalized_event_id' => $event->id,
                    'asset_id' => $event->asset_id,
                    'provider_id' => $event->provider_id,
                    'file_object_id' => null,
                    'source_attachment_id' => null,
                    'storage_path' => null,
                    'window_start' => $at->subSeconds(10),
                    'window_end' => $at->addSeconds(10),
                    'checksum' => null,
                    'created_at' => $at->addMinutes(2),
                    'updated_at' => $at->addMinutes(2),
                ];

                foreach ([['road_facing', 'road-camera.svg'], ['driver_facing', 'driver-camera.svg']] as [$role, $file]) {
                    $media[] = $base + [
                        'media_type' => 'snapshot',
                        'media_role' => $role,
                        'media_url' => "/showcase/{$file}",
                        'thumbnail_url' => "/showcase/{$file}",
                        'duration_seconds' => null,
                        'size_bytes' => $random->int(14_000, 64_000),
                        'mime_type' => 'image/svg+xml',
                        'captured_at' => $at,
                        'availability_status' => 'available',
                        'retrieval_status' => 'ready',
                        'metadata_json' => ['showcase' => true, 'camera' => $role],
                    ];
                }

                $clipStatus = match (true) {
                    $ageHours < 3 => ['pending', 'requested'],
                    $ageHours > 72 => ['expired', 'failed'],
                    default => ['not_available', 'failed'],
                };
                $media[] = $base + [
                    'media_type' => 'clip',
                    'media_role' => 'primary_evidence',
                    'media_url' => null,
                    'thumbnail_url' => '/showcase/road-camera.svg',
                    'duration_seconds' => 20,
                    'size_bytes' => null,
                    'mime_type' => 'video/mp4',
                    'captured_at' => $at,
                    'availability_status' => $clipStatus[0],
                    'retrieval_status' => $clipStatus[1],
                    'metadata_json' => ['showcase' => true, 'note' => 'Clip simulado: no hay archivo de video.'],
                ];

                $requestStatus = match (true) {
                    $ageHours < 1 => 'pending',
                    $ageHours < 3 => $random->pick(['sent', 'processing']),
                    $ageHours > 72 => 'expired',
                    default => $random->weighted(['completed' => 60, 'failed' => 40]),
                };
                $requests[] = [
                    'team_id' => $this->ctx->team->id,
                    'normalized_event_id' => $event->id,
                    'provider_id' => $event->provider_id,
                    'request_type' => 'fetch_video_clip',
                    'requested_at' => $at->addMinutes(1),
                    'status' => $requestStatus,
                    'response_metadata_json' => ['showcase' => true] + match ($requestStatus) {
                        'failed' => ['error' => 'La cámara no respondió: unidad sin cobertura celular.'],
                        'expired' => ['error' => 'El video ya no está en la SD de la cámara (se sobrescribe a las ~72 h).'],
                        default => ['retrieval_id' => 'showcase-'.$event->id],
                    },
                    'expires_at' => $at->addHours(72),
                    'completed_at' => in_array($requestStatus, ['completed', 'failed', 'expired'], true) ? $at->addMinutes($random->int(3, 40)) : null,
                    'sweep_only' => false,
                    'created_at' => $at->addMinutes(1),
                    'updated_at' => $at->addMinutes(1),
                ];
            }

            $this->bulkInsert('event_media_contexts', $media, timestamps: false);
            $this->bulkInsert('event_media_requests', $requests, timestamps: false);
        });
    }
}
