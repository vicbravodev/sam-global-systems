<?php

namespace App\Http\Controllers\Drivers;

use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Enums\ContactType;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Drivers\Models\DriverDocument;
use App\Domains\Drivers\Models\DriverStatusLog;
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

    /**
     * Historical assignments shown in the detail panel.
     */
    private const ASSIGNMENTS_LIMIT = 20;

    /**
     * Status log entries shown in the detail panel.
     */
    private const STATUS_LOG_LIMIT = 20;

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
            'columns' => fn () => $this->columnPresence($current_team),
        ]);
    }

    public function show(Team $current_team, Driver $driver): Response
    {
        // 404 before the policy check so a cross-team id never reveals the
        // driver exists (the BelongsToTenant scope on the binding already
        // filters, this keeps it explicit — same spirit as assets/show).
        abort_if($driver->team_id !== $current_team->id, 404);

        $this->authorize('view', $driver);

        $driver->load([
            'currentAssignment.asset',
            'riskProfile',
            'contacts' => fn (HasMany $q) => $q
                ->orderByDesc('is_primary')
                ->orderBy('id'),
            'documents' => fn (HasMany $q) => $q
                ->orderByDesc('expires_at')
                ->orderBy('id'),
        ]);

        return Inertia::render('drivers/show', [
            'driver' => $this->toDetail($driver),
            'assignments' => fn () => $this->assignments($driver),
            'statusLog' => fn () => $this->statusLog($driver),
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
     * Tenant-wide presence of the optional roster columns (F1.4). Computed
     * over ALL drivers of the team — not the current page — so a column that
     * is empty for the whole fleet disappears instead of painting "—" on
     * every row, and the layout stays stable across pages and filters.
     *
     * @return array{asset: bool, risk: bool, phone: bool, lastSeen: bool}
     */
    private function columnPresence(Team $team): array
    {
        $drivers = fn (): Builder => Driver::query()->where('team_id', $team->id);

        return [
            'asset' => DriverAssignment::query()
                ->where('team_id', $team->id)
                ->where('assignment_type', AssignmentType::PrimaryDriver)
                ->whereNull('ended_at')
                ->exists(),
            'risk' => $drivers()->whereHas('riskProfile')->exists(),
            'phone' => $drivers()
                ->whereHas('contacts', fn (Builder $q) => $q->where('contact_type', ContactType::MobilePhone))
                ->exists(),
            'lastSeen' => $drivers()->whereNotNull('last_seen_at')->exists(),
        ];
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

    /**
     * Full profile shape the detail page consumes: identity, current
     * assignment, risk profile, contacts and documents.
     *
     * @return array<string, mixed>
     */
    private function toDetail(Driver $driver): array
    {
        $asset = $driver->currentAssignment?->asset;
        $risk = $driver->riskProfile;

        return [
            'id' => (int) $driver->id,
            'fullName' => (string) $driver->full_name,
            'firstName' => $driver->first_name,
            'lastName' => $driver->last_name,
            'employeeCode' => $driver->employee_code,
            'externalPrimaryId' => $driver->external_primary_id,
            'status' => $driver->status->value,
            'firstSeenAt' => $driver->first_seen_at?->toIso8601String(),
            'lastSeenAt' => $driver->last_seen_at?->toIso8601String(),
            'currentAsset' => $asset ? [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'code' => $asset->code,
            ] : null,
            'riskProfile' => $risk ? [
                'riskScore' => $risk->risk_score !== null ? (float) $risk->risk_score : null,
                'riskLevel' => $risk->risk_level?->value,
                'incidentsCount' => (int) $risk->incidents_count,
                'harshEventsCount' => (int) $risk->harsh_events_count,
                'fatigueFlagsCount' => (int) $risk->fatigue_flags_count,
                'lastCalculatedAt' => $risk->last_calculated_at?->toIso8601String(),
            ] : null,
            'contacts' => $driver->contacts
                ->map(fn (DriverContact $contact) => [
                    'id' => (int) $contact->id,
                    'contactType' => $contact->contact_type->value,
                    'label' => $contact->label,
                    'value' => (string) $contact->value,
                    'isPrimary' => (bool) $contact->is_primary,
                    'isEmergency' => (bool) $contact->is_emergency,
                    'verifiedAt' => $contact->verified_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'documents' => $driver->documents
                ->map(fn (DriverDocument $document) => [
                    'id' => (int) $document->id,
                    'documentType' => $document->document_type->value,
                    'documentNumber' => $document->document_number,
                    'status' => $document->status->value,
                    'issuedAt' => $document->issued_at?->toDateString(),
                    'expiresAt' => $document->expires_at?->toDateString(),
                    'fileUrl' => $document->file_url,
                    'isExpired' => $document->isExpired(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Assignment history, newest first; flags the row that is still open.
     *
     * @return list<array<string, mixed>>
     */
    private function assignments(Driver $driver): array
    {
        return $driver->assignments()
            ->with('asset')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::ASSIGNMENTS_LIMIT)
            ->get()
            ->map(fn (DriverAssignment $assignment) => [
                'id' => (int) $assignment->id,
                'asset' => $assignment->asset ? [
                    'id' => (int) $assignment->asset->id,
                    'name' => (string) $assignment->asset->name,
                    'code' => $assignment->asset->code,
                ] : null,
                'assignmentType' => $assignment->assignment_type->value,
                'source' => $assignment->source->value,
                'startedAt' => $assignment->started_at?->toIso8601String(),
                'endedAt' => $assignment->ended_at?->toIso8601String(),
                'isCurrent' => $assignment->ended_at === null,
            ])
            ->all();
    }

    /**
     * Status transitions, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function statusLog(Driver $driver): array
    {
        return $driver->statusLogs()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(self::STATUS_LOG_LIMIT)
            ->get()
            ->map(fn (DriverStatusLog $log) => [
                'id' => (int) $log->id,
                'statusCode' => (string) $log->status_code,
                'statusLabel' => $log->status_label,
                'severity' => $log->severity?->value,
                'effectiveFrom' => $log->effective_from?->toIso8601String(),
                'effectiveTo' => $log->effective_to?->toIso8601String(),
            ])
            ->all();
    }
}
