<?php

namespace App\Http\Controllers\Drivers;

use App\Domains\Assets\Queries\LatestAssetTelemetry;
use App\Domains\Context\Actions\LoadRecentAssetHistory;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Enums\ContactType;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Enums\RiskLevel;
use App\Domains\Drivers\Jobs\RecalculateDriverRiskProfilesJob;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Drivers\Models\DriverDocument;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Domains\Drivers\Models\DriverStatusLog;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
     * Spanish labels for the provider-synced profile fields the detail page
     * surfaces (Samsara driver record: license, username, timezone...).
     * Unknown metadata keys are not shown — this is a curated allow-list.
     *
     * @var array<string, string>
     */
    private const PROVIDER_FIELD_LABELS = [
        'license_number' => 'Licencia',
        'license_state' => 'Estado de licencia',
        'username' => 'Usuario en proveedor',
        'activation_status' => 'Estado en proveedor',
        'static_vehicle' => 'Vehículo fijo (proveedor)',
        'timezone' => 'Zona horaria',
        'locale' => 'Idioma',
        'tags' => 'Etiquetas',
        'notes' => 'Notas',
    ];

    /**
     * Historical assignments shown in the detail panel.
     */
    private const ASSIGNMENTS_LIMIT = 20;

    /**
     * Status log entries shown in the detail panel.
     */
    private const STATUS_LOG_LIMIT = 20;

    /**
     * Recent normalized events shown in the detail activity feed.
     */
    private const RECENT_EVENTS_LIMIT = 15;

    /**
     * Linked incidents shown in the detail panel.
     */
    private const INCIDENTS_LIMIT = 10;

    /**
     * Days covered by the per-day activity sparkline on the detail page.
     */
    private const ACTIVITY_DAYS = 14;

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
                'contacts' => fn (Relation $q) => $q
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
            'summary' => fn () => $this->summary($current_team),
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
            'currentAssignment.asset.latestLocation',
            'riskProfile',
            'contacts' => fn (HasMany $q) => $q
                ->orderByDesc('is_primary')
                ->orderBy('id'),
            'documents' => fn (HasMany $q) => $q
                ->orderByDesc('expires_at')
                ->orderBy('id'),
        ]);
        // latestTelemetry without the one-of-many full-history scan.
        app(LatestAssetTelemetry::class)->loadInto(array_filter([$driver->currentAssignment?->asset]));

        return Inertia::render('drivers/show', [
            'driver' => $this->toDetail($driver),
            'assignments' => fn () => $this->assignments($driver),
            'statusLog' => fn () => $this->statusLog($driver),
            'recentEvents' => fn () => $this->recentEvents($driver),
            'incidents' => fn () => $this->incidents($driver),
            'activity' => fn () => $this->activity($driver),
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
     * Tenant-wide roster pulse for the header strip: how many drivers are in
     * each status, how many carry an elevated risk profile, how many have no
     * vehicle assigned right now and how many were seen in the last 24 h.
     * Ignores the active filters on purpose (it describes the whole roster).
     *
     * @return array{total: int, statuses: array<string, int>, highRisk: int, unassigned: int, seenToday: int}
     */
    private function summary(Team $team): array
    {
        $drivers = fn (): Builder => Driver::query()->where('team_id', $team->id);

        $byStatus = $drivers()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statuses = [];

        foreach (DriverStatus::cases() as $status) {
            $statuses[$status->value] = (int) ($byStatus[$status->value] ?? 0);
        }

        return [
            'total' => (int) $byStatus->sum(),
            'statuses' => $statuses,
            'highRisk' => $drivers()
                ->whereHas('riskProfile', fn (Builder $q) => $q->whereIn('risk_level', [RiskLevel::High, RiskLevel::Critical]))
                ->count(),
            'unassigned' => $drivers()->whereDoesntHave('currentAssignment')->count(),
            'seenToday' => $drivers()->where('last_seen_at', '>=', now()->subDay())->count(),
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
                    'label' => self::STATUS_LABELS[$status->value],
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
        $risk = $driver->riskProfile;

        return [
            'id' => $driver->id,
            'fullName' => $driver->full_name,
            'employeeCode' => $driver->employee_code,
            'status' => $driver->status->value,
            'currentAsset' => $asset !== null ? [
                'id' => $asset->id,
                'name' => $asset->name,
                'code' => $asset->code,
            ] : null,
            'riskScore' => $risk?->risk_score !== null
                ? (float) $risk->risk_score
                : null,
            'riskLevel' => $risk?->risk_level?->value,
            'riskTrend' => $risk?->metadata_json['trend'] ?? null,
            'incidentsCount' => $risk !== null ? $risk->incidents_count : 0,
            'harshEventsCount' => $risk !== null ? $risk->harsh_events_count : 0,
            'phone' => $phone?->value ?? $driver->phone,
            'lastSeenAt' => $driver->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * Full profile shape the detail page consumes: identity, current
     * assignment, risk profile, contacts, documents and the provider-synced
     * profile fields (license, username...).
     *
     * @return array<string, mixed>
     */
    private function toDetail(Driver $driver): array
    {
        $asset = $driver->currentAssignment?->asset;
        $risk = $driver->riskProfile;

        return [
            'id' => $driver->id,
            'fullName' => $driver->full_name,
            'firstName' => $driver->first_name,
            'lastName' => $driver->last_name,
            'employeeCode' => $driver->employee_code,
            'externalPrimaryId' => $driver->external_primary_id,
            'phone' => $driver->phone,
            'status' => $driver->status->value,
            'firstSeenAt' => $driver->first_seen_at?->toIso8601String(),
            'lastSeenAt' => $driver->last_seen_at?->toIso8601String(),
            // Latest REAL activity (driver event or current unit's signal)
            // — what the header shows as "visto". `last_seen_at` is the
            // roster-sync timestamp and lags hours behind the road.
            'lastSignalAt' => $this->lastSignalAt($driver),
            'currentAsset' => $asset !== null ? [
                'id' => $asset->id,
                'name' => $asset->name,
                'code' => $asset->code,
            ] : null,
            'riskProfile' => $risk !== null ? $this->presentRiskProfile($driver, $risk) : null,
            'providerFields' => $this->providerFields($driver),
            'contacts' => $driver->contacts
                ->map(fn (DriverContact $contact) => [
                    'id' => $contact->id,
                    'contactType' => $contact->contact_type->value,
                    'label' => $contact->label,
                    'value' => $contact->value,
                    'isPrimary' => $contact->is_primary,
                    'isEmergency' => $contact->is_emergency,
                    'verifiedAt' => $contact->verified_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'documents' => $driver->documents
                ->map(fn (DriverDocument $document) => [
                    'id' => $document->id,
                    'documentType' => $document->document_type->value,
                    'documentNumber' => $document->document_number,
                    'status' => $document->status->value,
                    'issuedAt' => $document->issued_at?->toDateString(),
                    'expiresAt' => $document->expires_at?->toDateString(),
                    'fileUrl' => $document->file_url,
                    'isExpired' => $document->isExpired(),
                    'daysToExpiry' => $document->expires_at !== null
                        ? (int) now()->startOfDay()->diffInDays($document->expires_at->startOfDay(), false)
                        : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Risk card: the nightly score/level plus live counters over the same
     * window the nightly job uses.
     *
     * @return array<string, mixed>
     */
    private function presentRiskProfile(Driver $driver, DriverRiskProfile $risk): array
    {
        $live = $this->liveRiskCounts($driver);

        return [
            'riskScore' => $risk->risk_score !== null ? (float) $risk->risk_score : null,
            'riskLevel' => $risk->risk_level?->value,
            'trend' => $risk->metadata_json['trend'] ?? null,
            'previousScore' => isset($risk->metadata_json['previous_score'])
                ? (float) $risk->metadata_json['previous_score']
                : null,
            'windowDays' => isset($risk->metadata_json['window_days'])
                ? (int) $risk->metadata_json['window_days']
                : null,
            // Counters are live over the same window the nightly job uses,
            // so a collision shows up right away; the score/level stay
            // the nightly calculation (see `lastCalculatedAt`).
            'incidentsCount' => $live['incidents'],
            'harshEventsCount' => $live['harsh'],
            'fatigueFlagsCount' => $live['fatigue'],
            'severeEventsCount' => $live['severe'],
            'lastCalculatedAt' => $risk->last_calculated_at?->toIso8601String(),
        ];
    }

    /**
     * Live counters for the risk card, computed exactly like
     * RecalculateDriverRiskProfilesJob (same window and code lists) but at
     * view time: one grouped event query plus one incident count.
     *
     * @return array{incidents: int, harsh: int, fatigue: int, severe: int}
     */
    private function liveRiskCounts(Driver $driver): array
    {
        $since = now()->subDays(RecalculateDriverRiskProfilesJob::WINDOW_DAYS);

        $counts = NormalizedEvent::query()
            ->where('normalized_events.team_id', $driver->team_id)
            ->where('normalized_events.driver_id', $driver->id)
            ->where('normalized_events.occurred_at', '>=', $since)
            ->join('event_types', 'event_types.id', '=', 'normalized_events.event_type_id')
            ->selectRaw('event_types.code as code, count(*) as total')
            ->groupBy('event_types.code')
            ->pluck('total', 'code');

        $sum = fn (array $codes): int => collect($codes)->sum(fn (string $code) => (int) ($counts[$code] ?? 0));

        return [
            'incidents' => Incident::query()
                ->where('team_id', $driver->team_id)
                ->where('driver_id', $driver->id)
                ->where('opened_at', '>=', $since)
                ->count(),
            'harsh' => $sum(LoadRecentAssetHistory::HARSH_DRIVING_CODES),
            'fatigue' => $sum(RecalculateDriverRiskProfilesJob::FATIGUE_CODES),
            'severe' => $sum(RecalculateDriverRiskProfilesJob::SEVERE_CODES),
        ];
    }

    /**
     * Newest real signal attributable to the driver: their latest normalized
     * event, or the latest position/telemetry of the unit they drive now.
     */
    private function lastSignalAt(Driver $driver): ?string
    {
        $asset = $driver->currentAssignment?->asset;

        $candidates = array_filter([
            NormalizedEvent::query()
                ->where('team_id', $driver->team_id)
                ->where('driver_id', $driver->id)
                ->max('occurred_at'),
            $asset?->latestLocation?->recorded_at,
            $asset?->latestTelemetry?->recorded_at,
            // MAX() de una columna datetime: null o un timestamp, nunca '0'.
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $newest = null;

        foreach ($candidates as $value) {
            $at = Carbon::parse($value);

            if ($newest === null || $at->gt($newest)) {
                $newest = $at;
            }
        }

        return $newest?->toIso8601String();
    }

    /**
     * Curated, labelled view of the provider-synced profile (the Samsara
     * driver record keeps license, username, timezone...). Only the allow-listed
     * keys are shown; list values are joined for display.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    private function providerFields(Driver $driver): array
    {
        $metadata = $driver->metadata_json ?? [];
        $fields = [];

        foreach (self::PROVIDER_FIELD_LABELS as $key => $label) {
            $value = $metadata[$key] ?? null;

            if (is_array($value)) {
                // Igual que el array_filter() sin callback: fuera null, '' y '0'.
                $value = implode(', ', array_filter(array_map(
                    fn ($item) => is_scalar($item) ? (string) $item : null,
                    $value,
                ), fn (?string $item): bool => ! in_array($item, [null, '', '0'], true)));
            }

            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            $fields[] = ['key' => $key, 'label' => $label, 'value' => (string) $value];
        }

        return $fields;
    }

    /**
     * Assignment history, newest first; flags the row that is still open.
     *
     * @return list<array<string, mixed>>
     */
    private function assignments(Driver $driver): array
    {
        return array_values(DriverAssignment::query()
            ->with('asset')
            ->where('driver_id', $driver->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::ASSIGNMENTS_LIMIT)
            ->get()
            ->map(fn (DriverAssignment $assignment) => [
                'id' => $assignment->id,
                'asset' => $assignment->asset !== null ? [
                    'id' => $assignment->asset->id,
                    'name' => $assignment->asset->name,
                    'code' => $assignment->asset->code,
                ] : null,
                'assignmentType' => $assignment->assignment_type->value,
                'source' => $assignment->source->value,
                'startedAt' => $assignment->started_at?->toIso8601String(),
                'endedAt' => $assignment->ended_at?->toIso8601String(),
                'isCurrent' => $assignment->ended_at === null,
            ])
            ->all());
    }

    /**
     * Status transitions, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function statusLog(Driver $driver): array
    {
        return array_values($driver->statusLogs()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(self::STATUS_LOG_LIMIT)
            ->get()
            ->map(fn (DriverStatusLog $log) => [
                'id' => $log->id,
                'statusCode' => $log->status_code,
                'statusLabel' => $log->status_label,
                'severity' => $log->severity?->value,
                'effectiveFrom' => $log->effective_from?->toIso8601String(),
                'effectiveTo' => $log->effective_to?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Latest normalized events attributed to this driver (safety events,
     * panic, fatigue...) so the profile reads as a live record, not a form.
     *
     * @return list<array<string, mixed>>
     */
    private function recentEvents(Driver $driver): array
    {
        return array_values(NormalizedEvent::query()
            ->where('team_id', $driver->team_id)
            ->where('driver_id', $driver->id)
            ->with(['eventType', 'eventCategory', 'eventSeverity', 'asset'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_EVENTS_LIMIT)
            ->get()
            ->map(fn (NormalizedEvent $event) => [
                'id' => $event->id,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
                'eventType' => $event->eventType?->name ?? $event->eventType?->code,
                'category' => $event->eventCategory?->name,
                'severity' => $event->eventSeverity?->code,
                'asset' => $event->asset !== null ? [
                    'id' => $event->asset->id,
                    'name' => $event->asset->name,
                ] : null,
            ])
            ->all());
    }

    /**
     * Incidents linked to this driver, newest first. Same rendered status
     * string as the inbox (C1-b).
     *
     * @return list<array<string, mixed>>
     */
    private function incidents(Driver $driver): array
    {
        return array_values(Incident::query()
            ->where('team_id', $driver->team_id)
            ->where('driver_id', $driver->id)
            ->with(['status', 'priority', 'type', 'currentAssignment'])
            ->orderByDesc('opened_at')
            ->limit(self::INCIDENTS_LIMIT)
            ->get()
            ->map(fn (Incident $incident) => [
                'id' => $incident->id,
                'reference' => $incident->reference(),
                'title' => $incident->title,
                'status' => $incident->status !== null ? [
                    'code' => $incident->status->code,
                    'uiStatus' => IncidentStatusPresenter::forIncident($incident),
                    'name' => IncidentStatusPresenter::labelForIncident($incident),
                ] : null,
                'priority' => $incident->priority !== null ? [
                    'code' => $incident->priority->code,
                    'name' => $incident->priority->name,
                ] : null,
                'type' => $incident->type?->name,
                'openedAt' => $incident->opened_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Events per day over the last ACTIVITY_DAYS days (oldest first, zero
     * filled) for the sparkline on the risk card. Bucketed in PHP: a single
     * driver's two-week window is small and this stays portable across
     * PostgreSQL and SQLite.
     *
     * @return list<array{date: string, count: int}>
     */
    private function activity(Driver $driver): array
    {
        $start = now()->subDays(self::ACTIVITY_DAYS - 1)->startOfDay();

        $counts = NormalizedEvent::query()
            ->where('team_id', $driver->team_id)
            ->where('driver_id', $driver->id)
            ->where('occurred_at', '>=', $start)
            ->pluck('occurred_at')
            ->countBy(fn ($occurredAt) => $occurredAt->toDateString());

        $series = [];

        for ($day = 0; $day < self::ACTIVITY_DAYS; $day++) {
            $date = $start->copy()->addDays($day)->toDateString();
            $series[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return $series;
    }
}
