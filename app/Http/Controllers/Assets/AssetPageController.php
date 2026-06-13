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
     * Location snapshots shown in the detail history panel.
     */
    private const LOCATION_HISTORY_LIMIT = 20;

    /**
     * Linked incidents shown in the detail panel.
     */
    private const INCIDENTS_LIMIT = 20;

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
        ]);
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

        return [
            'id' => (int) $asset->id,
            'name' => (string) $asset->name,
            'code' => $asset->code,
            'status' => $asset->status->value,
            'type' => $asset->assetType ? [
                'code' => (string) $asset->assetType->code,
                'name' => (string) $asset->assetType->name,
                'category' => $asset->assetType->category->value,
            ] : null,
            'devices' => $asset->devices
                ->map(fn (AssetDevice $device) => [
                    'id' => (int) $device->id,
                    'deviceType' => (string) $device->device_type,
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
        $driver = $asset->currentDriverAssignment?->driver;

        return [
            ...$this->toRow($asset),
            'externalPrimaryId' => $asset->external_primary_id,
            'provider' => $asset->provider?->name,
            'sourceIntegration' => $asset->sourceIntegration?->name,
            'firstSeenAt' => $asset->first_seen_at?->toIso8601String(),
            // Currently assigned primary driver, the reciprocal of the
            // asset link the driver detail already shows (C-08). Null when
            // nobody is assigned right now.
            'driver' => $driver ? [
                'id' => (int) $driver->id,
                'name' => (string) $driver->full_name,
                'employeeCode' => $driver->employee_code,
            ] : null,
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
                    'data' => $snapshot->data_json,
                    'recordedAt' => $snapshot->recorded_at->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
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
