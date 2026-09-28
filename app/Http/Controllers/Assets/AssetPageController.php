<?php

namespace App\Http\Controllers\Assets;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Location snapshots shown in the detail history panel.
     */
    private const LOCATION_HISTORY_LIMIT = 20;

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

    public function index(Request $request, Team $current_team): Response
    {
        // Assets are read-only and managed exclusively by integration sync
        // (spec 04 §9): EnsureTeamMembership on the route group is the whole
        // access check, there is no AssetPolicy.
        $filters = $this->filters($request);

        $query = Asset::query()
            ->where('team_id', $current_team->id)
            ->with([
                'assetType',
                'latestLocation',
                'latestTelemetry',
                'currentDriverAssignment.driver',
                // Only devices currently attached (mirrors AssetDevice::isAttached()).
                'devices' => fn (HasMany $q) => $q
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
        ]);
    }

    public function map(Team $current_team): Response
    {
        $assets = Asset::query()
            ->where('team_id', $current_team->id)
            ->with(['assetType', 'latestLocation'])
            ->get();

        [$positioned, $unpositioned] = $assets->partition(
            fn (Asset $asset) => $asset->latestLocation !== null,
        );

        return Inertia::render('assets/map', [
            'assets' => $positioned
                ->map(fn (Asset $asset) => $this->toMarker($asset))
                ->values()
                ->all(),
            'unpositionedCount' => $unpositioned->count(),
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
            'latestLocation',
            'latestTelemetry',
            'provider',
            'sourceIntegration',
            'currentDriverAssignment.driver',
            'devices' => fn (HasMany $q) => $q
                ->whereNull('detached_at')
                ->where('status', '!=', DeviceStatus::Detached)
                ->orderBy('attached_at'),
        ]);

        return Inertia::render('assets/show', [
            'asset' => $this->toDetail($asset),
            'telemetry' => fn () => $this->telemetry($asset),
            'locationHistory' => fn () => $this->locationHistory($asset),
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
            // Units whose latest position is fresh AND shows speed: the live
            // "on the road right now" figure.
            'moving' => $assets()
                ->whereHas('latestLocation', fn (Builder $s) => $s
                    ->where('recorded_at', '>=', $freshSince)
                    ->where('speed', '>', self::MOVING_SPEED_KPH))
                ->count(),
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
        return NormalizedEvent::query()
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
            ->all();
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
     * @return array{q: string|null, status: string|null, type: string|null}
     */
    private function filters(Request $request): array
    {
        $status = $request->filled('status')
            ? AssetStatus::tryFrom($request->string('status')->toString())?->value
            : null;

        return [
            'q' => $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            'status' => $status,
            'type' => $request->filled('type') ? $request->string('type')->toString() : null,
        ];
    }

    /**
     * @param  Builder<Asset>  $query
     * @param  array{q: string|null, status: string|null, type: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
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
     * @return array{statuses: list<array{value: string, label: string}>, types: list<array{value: string, label: string}>}
     */
    private function filterOptions(): array
    {
        return [
            'statuses' => array_map(
                fn (AssetStatus $status) => [
                    'value' => $status->value,
                    'label' => self::STATUS_LABELS[$status->value] ?? $status->value,
                ],
                AssetStatus::cases(),
            ),
            'types' => AssetType::query()
                ->orderBy('name')
                ->get(['code', 'name'])
                ->map(fn (AssetType $type) => [
                    'value' => (string) $type->code,
                    'label' => (string) $type->name,
                ])
                ->all(),
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
        $location = $asset->latestLocation;
        $driver = $asset->currentDriverAssignment?->driver;

        return [
            'id' => (int) $asset->id,
            'name' => (string) $asset->name,
            'code' => $asset->code,
            'status' => $asset->status->value,
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
            'lastLocation' => $location ? [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'formattedLocation' => $location->formatted_location,
                'speed' => $location->speed !== null ? (float) $location->speed : null,
                'heading' => $location->heading !== null ? (int) $location->heading : null,
                'recordedAt' => $location->recorded_at->toIso8601String(),
            ] : null,
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
            $asset->latestLocation?->recorded_at,
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
        $location = $asset->latestLocation;

        return [
            'id' => (int) $asset->id,
            'name' => (string) $asset->name,
            'code' => $asset->code,
            'status' => $asset->status->value,
            'category' => $asset->assetType?->category->value,
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'speed' => $location->speed !== null ? (float) $location->speed : null,
            'heading' => $location->heading !== null ? (int) $location->heading : null,
            'recordedAt' => $location->recorded_at->toIso8601String(),
        ];
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
        return collect(TelemetryType::cases())
            ->map(function (TelemetryType $type) use ($asset): ?array {
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
                    'label' => self::TELEMETRY_LABELS[$type->value] ?? $type->value,
                    'data' => $this->localizeTelemetryData($type, $snapshot->data_json),
                    'recordedAt' => $snapshot->recorded_at->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
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
     * Most recent location snapshots for the history panel.
     *
     * @return list<array<string, mixed>>
     */
    private function locationHistory(Asset $asset): array
    {
        return AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit(self::LOCATION_HISTORY_LIMIT)
            ->get()
            ->map(fn (AssetLocationSnapshot $snapshot) => [
                'id' => (int) $snapshot->id,
                'latitude' => (float) $snapshot->latitude,
                'longitude' => (float) $snapshot->longitude,
                'formattedLocation' => $snapshot->formatted_location,
                'speed' => $snapshot->speed !== null ? (float) $snapshot->speed : null,
                'heading' => $snapshot->heading !== null ? (int) $snapshot->heading : null,
                'source' => $snapshot->source->value,
                'recordedAt' => $snapshot->recorded_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Incidents linked to this asset, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function incidents(Asset $asset): array
    {
        return Incident::query()
            ->where('team_id', $asset->team_id)
            ->where('asset_id', $asset->id)
            ->with(['status', 'priority', 'type', 'currentAssignment'])
            ->orderByDesc('opened_at')
            ->limit(self::INCIDENTS_LIMIT)
            ->get()
            ->map(fn (Incident $incident) => [
                'id' => (int) $incident->id,
                'title' => (string) $incident->title,
                'status' => $incident->status ? [
                    'code' => (string) $incident->status->code,
                    'uiStatus' => IncidentStatusPresenter::uiStatus(
                        $incident->status->code,
                        $incident->currentAssignment !== null,
                    ),
                    // Same rendered string as inbox/detail/palette (C1-b).
                    'name' => IncidentStatusPresenter::label(
                        $incident->status->code,
                        $incident->currentAssignment !== null,
                    ),
                ] : null,
                'priority' => $incident->priority ? [
                    'code' => (string) $incident->priority->code,
                    'name' => (string) $incident->priority->name,
                ] : null,
                'type' => $incident->type?->name,
                'openedAt' => $incident->opened_at?->toIso8601String(),
            ])
            ->all();
    }
}
