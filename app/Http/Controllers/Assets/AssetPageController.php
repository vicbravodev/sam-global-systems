<?php

namespace App\Http\Controllers\Assets;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Assets\Models\AssetType;
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
        ];
    }
}
