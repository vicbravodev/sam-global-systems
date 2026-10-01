<?php

namespace App\Http\Controllers\Assets;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Assets\Queries\LatestAssetTelemetry;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetPageController extends Controller
{
    /**
     * Assets shown per page in the fleet list.
     */
    private const PER_PAGE = 50;

    /**
     * Spanish labels for the asset status filter dropdown.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'active' => 'Activo',
        'inactive' => 'Inactivo',
        'offline' => 'Sin conexión',
        'alert' => 'Alerta',
        'critical' => 'Crítico',
        'maintenance' => 'Mantenimiento',
    ];

    /**
     * Spanish labels for the telemetry panel on the detail page.
     *
     * @var array<string, string>
     */
    private const TELEMETRY_LABELS = [
        'speed' => 'Velocidad',
        'fuel' => 'Combustible',
        'temperature' => 'Temperatura',
        'camera_status' => 'Estado de cámara',
        'battery' => 'Batería',
        'ignition' => 'Ignición',
        'odometer' => 'Odómetro',
    ];

    /**
     * Spanish labels for categorical telemetry values, keyed by telemetry type.
     * Numeric readings carry their own display-ready unit and need no map.
     *
     * @var array<string, array<string, string>>
     */
    private const TELEMETRY_VALUE_LABELS = [
        'ignition' => [
            'running' => 'Encendido',
            'idle' => 'Ralentí',
            'off' => 'Apagado',
        ],
    ];

    /**
     * Spanish labels for the device types the integration sync registers.
     * Unknown types fall back to their raw code.
     *
     * @var array<string, string>
     */
    private const DEVICE_TYPE_LABELS = [
        'gateway' => 'Gateway',
        'camera' => 'Cámara',
        'dashcam' => 'Dashcam',
        'gps_tracker' => 'GPS',
    ];

    /**
     * Window of the detail page's trail and history. The feed stores a point
     * every few seconds while a unit moves, so the history is a time window,
     * not "the last N points" (which at that density would be minutes).
     */
    private const TRAIL_WINDOW_HOURS = 2;

    /** Points drawn on the detail map's trail, evenly thinned across the window. */
    private const TRAIL_MAX_POINTS = 400;

    /** History table: at most one row per this many seconds, newest first. */
    private const HISTORY_BUCKET_SECONDS = 60;

    /**
     * Linked incidents shown in the detail panel.
     */
    private const INCIDENTS_LIMIT = 20;

    /**
     * Recent normalized events shown in the detail activity feed.
     */
    private const RECENT_EVENTS_LIMIT = 15;

    /**
     * A unit counts as "reporting" when its latest real signal is younger
     * than this many minutes (matches the offline watchdog default, C1).
     */
    private const REPORTING_WINDOW_MINUTES = 15;

    /**
     * Speed (km/h) above which a fresh position counts as "in motion".
     */
    private const MOVING_SPEED_KPH = 5;

    public function index(Request $request, Team $current_team, ResolveAssetLimit $resolveAssetLimit): Response
    {
        // Asset inventory is managed by integration sync (spec 04 §9):
        // EnsureTeamMembership on the route group is the whole access check
        // for reading. Switching monitoring on/off lives in
        // AssetMonitoringController behind `assets.manage`.
        $filters = $this->filters($request);

        $query = Asset::query()
            ->where('team_id', $current_team->id)
            ->with([
                'assetType',
                'currentDriverAssignment.driver',
                // Only devices currently attached (mirrors AssetDevice::isAttached()).
                'devices' => fn (Relation $q) => $q
                    ->whereNull('detached_at')
                    ->where('status', '!=', DeviceStatus::Detached)
                    ->orderBy('attached_at'),
            ]);

        $this->applyFilters($query, $filters);

        $paginator = $query
            // Most recently seen first, with NULL last_seen_at sinking to the
            // end on both PostgreSQL (production) and SQLite (tests).
            ->orderByRaw('CASE WHEN last_seen_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // latestTelemetry / latestSpeedTelemetry without the one-of-many
        // full-history scan (see LatestAssetTelemetry).
        app(LatestAssetTelemetry::class)->loadInto($paginator->items());

        return Inertia::render('assets/index', [
            'assets' => collect($paginator->items())
                ->map(fn (Asset $asset) => $this->toRow($asset))
                ->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
            'filters' => $filters,
            'filterOptions' => fn () => $this->filterOptions(),
            'summary' => fn () => $this->summary($current_team),
            'monitoring' => fn () => $this->monitoring($current_team, $resolveAssetLimit),
        ]);
    }

    /**
     * Cupo de vigilancia del tenant: cuántas unidades vigila, cuántas quedaron
     * pendientes tras el sync y el tope contratado. Tope suave: `overCap`
     * sólo avisa que el excedente se cobra como extra por día.
     *
     * @return array{monitored: int, pending: int, excluded: int, cap: int|null, overCap: bool}
     */
    private function monitoring(Team $team, ResolveAssetLimit $resolveAssetLimit): array
    {
        $byState = Asset::query()
            ->where('team_id', $team->id)
            ->selectRaw('monitoring_state, COUNT(*) as aggregate')
            ->groupBy('monitoring_state')
            ->pluck('aggregate', 'monitoring_state');

        $monitored = (int) ($byState[AssetMonitoringState::Monitored->value] ?? 0);
        $cap = $resolveAssetLimit->execute((int) $team->id);

        return [
            'monitored' => $monitored,
            'pending' => (int) ($byState[AssetMonitoringState::Pending->value] ?? 0),
            'excluded' => (int) ($byState[AssetMonitoringState::Excluded->value] ?? 0),
            'cap' => $cap,
            'overCap' => $cap !== null && $monitored > $cap,
        ];
    }

    public function map(Team $current_team): Response
    {
        // One row per unit: the live position lives on the asset itself.
        $assets = Asset::query()
            ->where('team_id', $current_team->id)
            ->with(['assetType', 'currentDriverAssignment.driver'])
            ->get();

        $positioned = $assets->filter(
            fn (Asset $asset) => $this->hasLivePosition($asset),
        );

        return Inertia::render('assets/map', [
            'assets' => $positioned
                ->map(fn (Asset $asset) => $this->toMarker($asset))
                ->values()
                ->all(),
            'unpositionedCount' => $assets->count() - $positioned->count(),
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function show(Team $current_team, Asset $asset): Response
    {
        // The BelongsToTenant scope already filters the binding, but the check
        // stays explicit (defense in depth, same spirit as the index query).
        abort_if($asset->team_id !== $current_team->id, 404);

        $asset->load([
            'assetType',
            'provider',
            'sourceIntegration',
            'currentDriverAssignment.driver',
            'devices' => fn (HasMany $q) => $q
                ->whereNull('detached_at')
                ->where('status', '!=', DeviceStatus::Detached)
                ->orderBy('attached_at'),
        ]);
        app(LatestAssetTelemetry::class)->loadInto([$asset]);

        return Inertia::render('assets/show', [
            'asset' => $this->toDetail($asset),
            'telemetry' => fn () => $this->telemetry($asset),
            'locationHistory' => fn () => $this->locationHistory($asset),
            'locationTrail' => fn () => $this->locationTrail($asset),
            'trailWindowHours' => self::TRAIL_WINDOW_HOURS,
            'incidents' => fn () => $this->incidents($asset),
            'recentEvents' => fn () => $this->recentEvents($asset),
        ]);
    }

    /**
     * Tenant-wide fleet pulse for the header strip: units that reported a
     * real signal in the last 15 minutes, units silent for more than a day,
     * units in alert/critical, in maintenance and camera-equipped. Ignores
     * the active filters on purpose (it describes the whole fleet).
     *
     * @return array{total: int, statuses: array<string, int>, reporting: int, silent: int, alerting: int, maintenance: int, withCamera: int, moving: int}
     */
    private function summary(Team $team): array
    {
        $assets = fn (): Builder => Asset::query()->where('team_id', $team->id);
        $freshSince = now()->subMinutes(self::REPORTING_WINDOW_MINUTES);
        $silentSince = now()->subDay();

        $byStatus = $assets()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statuses = [];

        foreach (AssetStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        return [
            'total' => (int) $byStatus->sum(),
            'statuses' => $statuses,
            'reporting' => $assets()
                ->where(fn (Builder $q) => $q
                    ->whereHas('locationSnapshots', fn (Builder $s) => $s->where('recorded_at', '>=', $freshSince))
                    ->orWhereHas('telemetrySnapshots', fn (Builder $s) => $s->where('recorded_at', '>=', $freshSince)))
                ->count(),
            'silent' => $assets()
                ->whereDoesntHave('locationSnapshots', fn (Builder $s) => $s->where('recorded_at', '>=', $silentSince))
                ->whereDoesntHave('telemetrySnapshots', fn (Builder $s) => $s->where('recorded_at', '>=', $silentSince))
                ->count(),
            'alerting' => $assets()
                ->whereIn('status', [AssetStatus::Alert, AssetStatus::Critical])
                ->count(),
            'maintenance' => $assets()->where('status', AssetStatus::Maintenance)->count(),
            'withCamera' => $assets()
                ->whereHas('devices', fn (Builder $d) => $d
                    ->whereIn('device_type', ['camera', 'dashcam'])
                    ->whereNull('detached_at')
                    ->where('status', '!=', DeviceStatus::Detached))
                ->count(),
            // Units whose CURRENT speed (newest of position and speed
            // telemetry, same reading the rows show) is fresh and above the
            // motion threshold: the live "on the road right now" figure.
            'moving' => $this->movingCount($assets, $freshSince),
        ];
    }

    /**
     * Counts units whose current speed reading is fresh and above the motion
     * threshold. Only units with a fresh position or speed reading are
     * loaded, so the candidate set stays bounded by the live fleet.
     *
     * @param  \Closure(): Builder<Asset>  $assets
     */
    private function movingCount(\Closure $assets, \DateTimeInterface $freshSince): int
    {
        // "Latest speed reading is fresh" == "some speed reading is fresh":
        // whereHas on the latestOfMany relation joined an unconstrained
        // MAX(recorded_at) GROUP BY asset_id over the whole snapshot table
        // (every tenant); this EXISTS uses the (asset_id, type, recorded_at)
        // index. The position comes from the asset's own live columns.
        return $assets()
            ->where(fn (Builder $q) => $q
                ->where('last_location_at', '>=', $freshSince)
                ->orWhereHas('telemetrySnapshots', fn (Builder $s) => $s
                    ->where('telemetry_type', TelemetryType::Speed)
                    ->where('recorded_at', '>=', $freshSince)))
            ->get()
            ->tap(fn ($candidates) => app(LatestAssetTelemetry::class)->loadInto($candidates))
            ->filter(function (Asset $asset): bool {
                $speed = $this->currentSpeed($asset);

                return $speed !== null && ! $speed['stale'] && $speed['kph'] > self::MOVING_SPEED_KPH;
            })
            ->count();
    }

    /**
     * The one "current speed" every surface shows (fleet row, detail header,
     * detail telemetry card): the NEWEST reading among the latest position
     * snapshot and the latest speed telemetry. `stale` flags readings older
     * than the reporting window, so a 27-minute-old 83 km/h is never
     * presented as live motion.
     *
     * @return array{kph: float, recordedAt: string, source: string, stale: bool}|null
     */
    private function currentSpeed(Asset $asset): ?array
    {
        $candidates = [];

        $locationAt = $asset->last_location_at;

        if ($locationAt !== null && $this->hasLivePosition($asset) && $asset->last_speed_kph !== null) {
            $candidates[] = ['kph' => (float) $asset->last_speed_kph, 'at' => $locationAt, 'source' => 'location'];
        }

        $telemetry = $asset->latestSpeedTelemetry;
        $value = $telemetry?->data_json['value'] ?? null;

        if ($telemetry !== null && is_numeric($value)) {
            $candidates[] = ['kph' => (float) $value, 'at' => $telemetry->recorded_at, 'source' => 'telemetry'];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => $b['at'] <=> $a['at']);
        $newest = $candidates[0];

        return [
            'kph' => $newest['kph'],
            'recordedAt' => $newest['at']->toIso8601String(),
            'source' => $newest['source'],
            'stale' => $newest['at']->lt(now()->subMinutes(self::REPORTING_WINDOW_MINUTES)),
        ];
    }

    /**
     * Latest normalized events attributed to this asset (safety, panic,
     * offline...) so the detail reads as a live record.
     *
     * @return list<array<string, mixed>>
     */
    private function recentEvents(Asset $asset): array
    {
        return array_values(NormalizedEvent::query()
            ->where('team_id', $asset->team_id)
            ->where('asset_id', $asset->id)
            ->with(['eventType', 'eventCategory', 'eventSeverity', 'driver'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_EVENTS_LIMIT)
            ->get()
            ->map(fn (NormalizedEvent $event) => [
                'id' => (int) $event->id,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
                'eventType' => $event->eventType?->name ?? $event->eventType?->code,
                'category' => $event->eventCategory?->name,
                'severity' => $event->eventSeverity?->code,
                'driver' => $event->driver ? [
                    'id' => (int) $event->driver->id,
                    'name' => (string) $event->driver->full_name,
                ] : null,
            ])
            ->all());
    }

    /**
     * Vehicle facts the integration sync stores in `metadata_json` (Samsara:
     * make/model/year, plate, VIN). Null when nothing is known so the UI can
     * hide the block instead of painting dashes.
     *
     * @return array{make: string|null, model: string|null, year: int|null, plate: string|null, vin: string|null, hasCamera: bool}|null
     */
    private function vehicle(Asset $asset): ?array
    {
        $metadata = $asset->metadata_json ?? [];

        $vehicle = [
            'make' => isset($metadata['make']) && is_scalar($metadata['make']) ? (string) $metadata['make'] : null,
            'model' => isset($metadata['model']) && is_scalar($metadata['model']) ? (string) $metadata['model'] : null,
            'year' => isset($metadata['year']) && is_numeric($metadata['year']) ? (int) $metadata['year'] : null,
            'plate' => isset($metadata['license_plate']) && is_scalar($metadata['license_plate']) ? (string) $metadata['license_plate'] : null,
            'vin' => isset($metadata['vin']) && is_scalar($metadata['vin']) ? (string) $metadata['vin'] : null,
            'hasCamera' => (bool) ($metadata['has_camera'] ?? false),
        ];

        $known = array_filter($vehicle, fn ($value) => $value !== null && $value !== false);

        return $known === [] ? null : $vehicle;
    }

    /**
     * Resolve the active fleet filters from the request query string. An
     * unknown status value is dropped so the prop mirrors what was applied.
     *
     * @return array{q: string|null, status: string|null, type: string|null, monitoring: string|null}
     */
    private function filters(Request $request): array
    {
        $status = $request->filled('status')
            ? AssetStatus::tryFrom($request->string('status')->toString())?->value
            : null;

        $monitoring = $request->filled('monitoring')
            ? AssetMonitoringState::tryFrom($request->string('monitoring')->toString())?->value
            : null;

        return [
            'q' => $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            'status' => $status,
            'type' => $request->filled('type') ? $request->string('type')->toString() : null,
            'monitoring' => $monitoring,
        ];
    }

    /**
     * @param  Builder<Asset>  $query
     * @param  array{q: string|null, status: string|null, type: string|null, monitoring: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($filters['monitoring'] ?? null) !== null) {
            $query->where('monitoring_state', $filters['monitoring']);
        }

        if ($filters['q'] !== null && $filters['q'] !== '') {
            // LOWER(...) LIKE keeps the search case-insensitive on both
            // PostgreSQL (production) and SQLite (tests) without ILIKE.
            $term = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $filters['q'])).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(code) LIKE ?', [$term]));
        }

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['type'] !== null) {
            $type = $filters['type'];
            $query->whereHas('assetType', fn (Builder $q) => $q->where('code', $type));
        }
    }

    /**
     * Reference lists used to populate the fleet filter dropdowns. AssetType
     * is a global seeded catalog (no team scope), so listing it all is fine.
     *
     * @return array{statuses: list<array{value: string, label: string}>, types: list<array{value: string, label: string}>, monitoring: list<array{value: string, label: string}>}
     */
    private function filterOptions(): array
    {
        return [
            'monitoring' => array_map(
                fn (AssetMonitoringState $state) => [
                    'value' => $state->value,
                    'label' => $state->label(),
                ],
                AssetMonitoringState::cases(),
            ),
            'statuses' => array_map(
                fn (AssetStatus $status) => [
                    'value' => $status->value,
                    'label' => self::STATUS_LABELS[$status->value],
                ],
                AssetStatus::cases(),
            ),
            'types' => array_values(AssetType::query()
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn (AssetType $type) => [
                    'value' => (string) $type->code,
                    'label' => (string) $type->name,
                ])
                ->all()),
        ];
    }

    /**
     * Flatten an asset into the row shape the fleet list consumes. Decimal
     * casts serialize as strings, so coordinates/speed are cast to float here.
     *
     * @return array<string, mixed>
     */
    private function toRow(Asset $asset): array
    {
        $driver = $asset->currentDriverAssignment?->driver;

        return [
            'id' => (int) $asset->id,
            'name' => (string) $asset->name,
            'code' => $asset->code,
            'status' => $asset->status->value,
            'monitoringState' => $asset->monitoring_state->value,
            'vehicle' => $this->vehicle($asset),
            // Currently assigned primary driver (reciprocal of the driver
            // roster's "activo asignado" column). Null when nobody is assigned.
            'driver' => $driver ? [
                'id' => (int) $driver->id,
                'name' => (string) $driver->full_name,
                'employeeCode' => $driver->employee_code,
            ] : null,
            'type' => $asset->assetType ? [
                'code' => (string) $asset->assetType->code,
                'name' => (string) $asset->assetType->name,
                'category' => $asset->assetType->category->value,
            ] : null,
            'devices' => $asset->devices
                ->map(fn (AssetDevice $device) => [
                    'id' => (int) $device->id,
                    'deviceType' => (string) $device->device_type,
                    'label' => self::DEVICE_TYPE_LABELS[$device->device_type] ?? (string) $device->device_type,
                    'externalDeviceId' => $device->external_device_id,
                    'status' => $device->status->value,
                ])
                ->values()
                ->all(),
            'lastLocation' => $this->hasLivePosition($asset) ? [
                'latitude' => (float) $asset->last_latitude,
                'longitude' => (float) $asset->last_longitude,
                'formattedLocation' => $asset->last_formatted_location,
                'speed' => $asset->last_speed_kph !== null ? (float) $asset->last_speed_kph : null,
                'heading' => $asset->last_heading,
                'recordedAt' => $asset->last_location_at->toIso8601String(),
            ] : null,
            'currentSpeed' => $this->currentSpeed($asset),
            'lastSeenAt' => $asset->last_seen_at?->toIso8601String(),
            // Most recent REAL signal (location or telemetry) — what the UI
            // shows as "seen". Never derived from the inventory-sync
            // timestamp, which bumps in bulk for the whole fleet (C1-a).
            'lastSignalAt' => $this->lastSignalAt($asset),
        ];
    }

    /**
     * Timestamp of the asset's latest real signal: its newest location or
     * telemetry snapshot. Null when the asset has never reported anything.
     */
    private function lastSignalAt(Asset $asset): ?string
    {
        $candidates = array_filter([
            $asset->last_location_at,
            $asset->latestTelemetry?->recorded_at,
        ]);

        if ($candidates === []) {
            return null;
        }

        return max($candidates)->toIso8601String();
    }

    /**
     * Minimal shape the live map needs per asset. Kept lighter than toRow()
     * (no devices) so the map payload stays small for large fleets.
     *
     * @return array<string, mixed>
     */
    private function toMarker(Asset $asset): array
    {
        return [
            'id' => (int) $asset->id,
            'name' => (string) $asset->name,
            'code' => $asset->code,
            'status' => $asset->status->value,
            'category' => $asset->assetType?->category->value,
            'latitude' => (float) $asset->last_latitude,
            'longitude' => (float) $asset->last_longitude,
            'speed' => $asset->last_speed_kph !== null ? (float) $asset->last_speed_kph : null,
            'heading' => $asset->last_heading,
            'recordedAt' => $asset->last_location_at?->toIso8601String(),
            'driver' => $asset->currentDriverAssignment?->driver?->full_name,
        ];
    }

    /**
     * @phpstan-assert-if-true !null $asset->last_location_at
     */
    private function hasLivePosition(Asset $asset): bool
    {
        return $asset->last_location_at !== null
            && $asset->last_latitude !== null
            && $asset->last_longitude !== null;
    }

    /**
     * Row shape plus the extra fields only the detail page shows.
     *
     * @return array<string, mixed>
     */
    private function toDetail(Asset $asset): array
    {
        return [
            ...$this->toRow($asset),
            'externalPrimaryId' => $asset->external_primary_id,
            'provider' => $asset->provider?->name,
            'sourceIntegration' => $asset->sourceIntegration?->name,
            'firstSeenAt' => $asset->first_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Latest telemetry snapshot per type, skipping types with no data. One
     * indexed query per enum case (7 max) keeps it simple and bounded.
     *
     * @return list<array<string, mixed>>
     */
    private function telemetry(Asset $asset): array
    {
        return array_values(collect(TelemetryType::cases())
            ->map(function (TelemetryType $type) use ($asset): ?array {
                // Speed shows the same "current speed" as the header tile
                // (newest of position and telemetry), never a second number.
                if ($type === TelemetryType::Speed) {
                    $speed = $this->currentSpeed($asset);

                    return $speed === null ? null : [
                        'type' => $type->value,
                        'label' => self::TELEMETRY_LABELS[$type->value],
                        'data' => ['value' => $speed['kph'], 'unit' => 'km/h'],
                        'recordedAt' => $speed['recordedAt'],
                        'stale' => $speed['stale'],
                    ];
                }

                $snapshot = AssetTelemetrySnapshot::query()
                    ->where('asset_id', $asset->id)
                    ->where('telemetry_type', $type)
                    ->orderByDesc('recorded_at')
                    ->first();

                if ($snapshot === null) {
                    return null;
                }

                return [
                    'type' => $type->value,
                    'label' => self::TELEMETRY_LABELS[$type->value],
                    'data' => $this->localizeTelemetryData($type, $snapshot->data_json),
                    'recordedAt' => $snapshot->recorded_at->toIso8601String(),
                ];
            })
            ->filter()
            ->all());
    }

    /**
     * Render categorical readings (ignition) in Spanish. The stored payload stays
     * provider-neutral — translation belongs to the view layer, not to the
     * telemetry the poller writes.
     *
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function localizeTelemetryData(TelemetryType $type, ?array $data): ?array
    {
        $labels = self::TELEMETRY_VALUE_LABELS[$type->value] ?? null;

        if ($data === null || $labels === null || ! is_string($data['value'] ?? null)) {
            return $data;
        }

        $data['value'] = $labels[$data['value']] ?? $data['value'];

        return $data;
    }

    /**
     * History table of the detail page: the last TRAIL_WINDOW_HOURS, at most
     * one row per HISTORY_BUCKET_SECONDS (the newest point of each bucket),
     * newest first. A unit parked all window long shows its few real fixes.
     *
     * @return list<array<string, mixed>>
     */
    private function locationHistory(Asset $asset): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->windowPoints($asset)->reverse() as $snapshot) {
            $bucket = intdiv($snapshot->recorded_at->getTimestamp(), self::HISTORY_BUCKET_SECONDS);

            if (isset($seen[$bucket])) {
                continue;
            }

            $seen[$bucket] = true;
            $rows[] = [
                'id' => (int) $snapshot->id,
                'latitude' => (float) $snapshot->latitude,
                'longitude' => (float) $snapshot->longitude,
                'formattedLocation' => $snapshot->formatted_location,
                'speed' => $snapshot->speed !== null ? (float) $snapshot->speed : null,
                'heading' => $snapshot->heading !== null ? (int) $snapshot->heading : null,
                'source' => $snapshot->source->value,
                'recordedAt' => $snapshot->recorded_at->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * The route over the window for the detail map, oldest first, evenly
     * thinned to TRAIL_MAX_POINTS. The newest point always stays so the line
     * ends on the marker.
     *
     * @return list<array{latitude: float, longitude: float, recordedAt: string}>
     */
    private function locationTrail(Asset $asset): array
    {
        $points = $this->windowPoints($asset)->values();
        $count = $points->count();
        $step = max(1, (int) ceil($count / self::TRAIL_MAX_POINTS));

        return array_values($points
            ->filter(fn ($snapshot, int $index) => $index % $step === 0 || $index === $count - 1)
            ->map(fn (AssetLocationSnapshot $snapshot) => [
                'latitude' => (float) $snapshot->latitude,
                'longitude' => (float) $snapshot->longitude,
                'recordedAt' => $snapshot->recorded_at->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Every stored point of the window, oldest first, over the unique
     * (asset_id, recorded_at) index.
     *
     * @return EloquentCollection<int, AssetLocationSnapshot>
     */
    private function windowPoints(Asset $asset): EloquentCollection
    {
        return AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('recorded_at', '>=', now()->subHours(self::TRAIL_WINDOW_HOURS))
            ->orderBy('recorded_at')
            ->get(['id', 'latitude', 'longitude', 'formatted_location', 'speed', 'heading', 'source', 'recorded_at']);
    }

    /**
     * Incidents linked to this asset, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function incidents(Asset $asset): array
    {
        return array_values(Incident::query()
            ->where('team_id', $asset->team_id)
            ->where('asset_id', $asset->id)
            ->with(['status', 'priority', 'type', 'currentAssignment'])
            ->orderByDesc('opened_at')
            ->limit(self::INCIDENTS_LIMIT)
            ->get()
            ->map(fn (Incident $incident) => [
                'id' => (int) $incident->id,
                'reference' => $incident->reference(),
                'title' => (string) $incident->title,
                'status' => $incident->status ? [
                    'code' => (string) $incident->status->code,
                    'uiStatus' => IncidentStatusPresenter::forIncident($incident),
                    // Same rendered string as inbox/detail/palette (C1-b).
                    'name' => IncidentStatusPresenter::labelForIncident($incident),
                ] : null,
                'priority' => $incident->priority ? [
                    'code' => (string) $incident->priority->code,
                    'name' => (string) $incident->priority->name,
                ] : null,
                'type' => $incident->type?->name,
                'openedAt' => $incident->opened_at?->toIso8601String(),
            ])
            ->all());
    }
}
