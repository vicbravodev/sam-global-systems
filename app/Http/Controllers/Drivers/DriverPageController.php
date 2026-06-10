<?php

namespace App\Http\Controllers\Drivers;

use App\Domains\Drivers\Enums\ContactType;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DriverPageController extends Controller
{
    /**
     * Drivers shown per page in the roster list.
     */
    private const PER_PAGE = 50;

    /**
     * Spanish labels for the driver status filter dropdown.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'active' => 'Activo',
        'off_duty' => 'Fuera de turno',
        'unavailable' => 'No disponible',
        'suspended' => 'Suspendido',
        'under_review' => 'En revisión',
    ];

    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Driver::class);

        $filters = $this->filters($request);

        $query = Driver::query()
            ->where('team_id', $current_team->id)
            ->with([
                'currentAssignment.asset',
                'riskProfile',
                // Only phone contacts; the roster shows the primary one first.
                'contacts' => fn (HasMany $q) => $q
                    ->where('contact_type', ContactType::MobilePhone)
                    ->orderByDesc('is_primary')
                    ->orderBy('id'),
            ]);

        $this->applyFilters($query, $filters);

        $paginator = $query
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('drivers/index', [
            'drivers' => collect($paginator->items())
                ->map(fn (Driver $driver) => $this->toRow($driver))
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
     * Resolve the active roster filters from the request query string. An
     * unknown status value is dropped so the prop mirrors what was applied.
     *
     * @return array{q: string|null, status: string|null}
     */
    private function filters(Request $request): array
    {
        $status = $request->filled('status')
            ? DriverStatus::tryFrom($request->string('status')->toString())?->value
            : null;

        return [
            'q' => $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            'status' => $status,
        ];
    }

    /**
     * @param  Builder<Driver>  $query
     * @param  array{q: string|null, status: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== null && $filters['q'] !== '') {
            // LOWER(...) LIKE keeps the search case-insensitive on both
            // PostgreSQL (production) and SQLite (tests) without ILIKE.
            $term = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $filters['q'])).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(full_name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(employee_code) LIKE ?', [$term]));
        }

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * @return array{statuses: list<array{value: string, label: string}>}
     */
    private function filterOptions(): array
    {
        return [
            'statuses' => array_map(
                fn (DriverStatus $status) => [
                    'value' => $status->value,
                    'label' => self::STATUS_LABELS[$status->value] ?? $status->value,
                ],
                DriverStatus::cases(),
            ),
        ];
    }

    /**
     * Flatten a driver into the row shape the roster list consumes. The risk
     * score is a decimal cast (serialized as string), so it is cast to float.
     *
     * @return array<string, mixed>
     */
    private function toRow(Driver $driver): array
    {
        $asset = $driver->currentAssignment?->asset;
        $phone = $driver->contacts->first();

        return [
            'id' => (int) $driver->id,
            'fullName' => (string) $driver->full_name,
            'employeeCode' => $driver->employee_code,
            'status' => $driver->status->value,
            'currentAsset' => $asset ? [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'code' => $asset->code,
            ] : null,
            'riskScore' => $driver->riskProfile?->risk_score !== null
                ? (float) $driver->riskProfile->risk_score
                : null,
            'phone' => $phone?->value,
            'lastSeenAt' => $driver->last_seen_at?->toIso8601String(),
        ];
    }
}
