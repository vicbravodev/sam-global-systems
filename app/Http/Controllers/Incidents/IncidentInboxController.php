<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Incidents\Support\IncidentInboxPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IncidentInboxController extends Controller
{
    /**
     * Maximum number of incidents loaded into the inbox in a single page.
     */
    private const INBOX_LIMIT = 200;

    public function __construct(
        private readonly IncidentInboxPresenter $presenter,
    ) {}

    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Incident::class);

        $filters = $this->filters($request);

        $query = Incident::query()
            ->where('team_id', $current_team->id)
            ->with([
                'type',
                'status',
                'priority',
                'currentAssignment',
                'asset',
                'driver',
                'relatedEvent.provider',
                'relatedEvent.eventType',
                'relatedEvent.eventSeverity',
                'aiEvaluation',
            ]);

        $this->applyFilters($query, $filters);

        /** @var EloquentCollection<int, Incident> $incidents */
        $incidents = $query
            ->orderByDesc('opened_at')
            ->limit(self::INBOX_LIMIT)
            ->get();

        $users = $this->resolveUsers(
            $incidents->map(fn (Incident $incident) => $incident->currentAssignment)
                ->filter(fn ($assignment) => $assignment?->assigned_to_type === AssigneeType::User)
                ->map(fn ($assignment) => (int) $assignment->assigned_to_id),
        );

        return Inertia::render('incidents/index', [
            'incidents' => $incidents
                ->map(fn (Incident $incident) => $this->presenter->toRow($incident, $users))
                ->all(),
            'filters' => $filters,
            'filterOptions' => fn () => $this->filterOptions($current_team),
            'members' => fn () => $this->members($current_team),
            'reclassifyOptions' => fn () => $this->reclassifyOptions(),
        ]);
    }

    /**
     * Resolve the active inbox filters from the request query string.
     *
     * @return array{q: string|null, severity: string|null, status: string|null, provider: string|null, shift: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'q' => $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            'severity' => $request->filled('severity') ? $request->string('severity')->toString() : null,
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            'provider' => $request->filled('provider') ? $request->string('provider')->toString() : null,
            'shift' => $request->filled('shift') ? $request->string('shift')->toString() : null,
        ];
    }

    /**
     * @param  Builder<Incident>  $query
     * @param  array{q: string|null, severity: string|null, status: string|null, provider: string|null, shift: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== null && $filters['q'] !== '') {
            // LOWER(...) LIKE keeps the search case-insensitive on both
            // PostgreSQL (production) and SQLite (tests) without ILIKE.
            $term = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $filters['q'])).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(summary) LIKE ?', [$term]));
        }

        if ($filters['severity'] !== null) {
            $priorityId = IncidentPriority::query()->where('code', $filters['severity'])->value('id');
            $query->where('incident_priority_id', $priorityId ?? 0);
        }

        if ($filters['status'] !== null) {
            $statusId = IncidentStatus::query()->where('code', $filters['status'])->value('id');
            $query->where('incident_status_id', $statusId ?? 0);
        }

        if ($filters['provider'] !== null) {
            $provider = $filters['provider'];
            $query->whereHas('relatedEvent.provider', fn (Builder $q) => $q->where('name', $provider));
        }

        if ($filters['shift'] !== null) {
            $hour = $this->hourExpression($query);

            if ($filters['shift'] === 'morning') {
                $query->whereRaw("{$hour} >= 6 AND {$hour} < 14");
            } elseif ($filters['shift'] === 'afternoon') {
                $query->whereRaw("{$hour} >= 14 AND {$hour} < 22");
            } elseif ($filters['shift'] === 'night') {
                // Night wraps past midnight: 22:00–23:59 and 00:00–05:59.
                $query->whereRaw("{$hour} >= 22 OR {$hour} < 6");
            }
        }
    }

    /**
     * SQL expression that yields the hour-of-day for `opened_at`, portable
     * across PostgreSQL (production) and SQLite (tests).
     *
     * @param  Builder<Incident>  $query
     */
    private function hourExpression(Builder $query): string
    {
        return $query->getModel()->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', opened_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM opened_at)';
    }

    /**
     * Reference lists used to populate the inbox filter dropdowns.
     *
     * @return array{severities: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>, providers: list<string>, shifts: list<array{value: string, label: string}>}
     */
    private function filterOptions(Team $current_team): array
    {
        $providers = DB::table('incidents')
            ->join('normalized_events', 'incidents.related_event_id', '=', 'normalized_events.id')
            ->join('integration_providers', 'normalized_events.provider_id', '=', 'integration_providers.id')
            ->where('incidents.team_id', $current_team->id)
            ->whereNull('incidents.deleted_at')
            ->distinct()
            ->orderBy('integration_providers.name')
            ->pluck('integration_providers.name')
            ->all();

        return [
            'severities' => IncidentPriority::query()
                ->orderBy('id')
                ->get(['code', 'name'])
                ->map(fn (IncidentPriority $p) => ['value' => (string) $p->code, 'label' => (string) $p->name])
                ->all(),
            'statuses' => IncidentStatus::query()
                ->orderBy('id')
                ->get(['code', 'name'])
                ->map(fn (IncidentStatus $s) => ['value' => (string) $s->code, 'label' => (string) $s->name])
                ->all(),
            'providers' => $providers,
            'shifts' => [
                ['value' => 'morning', 'label' => 'Mañana (06–14)'],
                ['value' => 'afternoon', 'label' => 'Tarde (14–22)'],
                ['value' => 'night', 'label' => 'Noche (22–06)'],
            ],
        ];
    }

    /**
     * Team members eligible to be assigned an incident.
     *
     * @return list<array{id: int, name: string}>
     */
    private function members(Team $current_team): array
    {
        return $current_team->members()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->all();
    }

    /**
     * Incident type/priority options used by the reclassify dialog.
     *
     * @return array{types: list<array{id: int, code: string, name: string}>, priorities: list<array{id: int, code: string, name: string}>}
     */
    private function reclassifyOptions(): array
    {
        return [
            'types' => IncidentType::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (IncidentType $t) => ['id' => (int) $t->id, 'code' => (string) $t->code, 'name' => (string) $t->name])
                ->all(),
            'priorities' => IncidentPriority::query()
                ->orderBy('id')
                ->get(['id', 'code', 'name'])
                ->map(fn (IncidentPriority $p) => ['id' => (int) $p->id, 'code' => (string) $p->code, 'name' => (string) $p->name])
                ->all(),
        ];
    }

    public function show(Team $current_team, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $incident->load([
            'type',
            'status',
            'priority',
            'currentAssignment',
            'asset',
            'driver',
            'relatedEvent.provider',
            'relatedEvent.eventType',
            'relatedEvent.eventSeverity',
            'aiEvaluation',
            'timeline',
            'comments',
            'evidence',
            'eventLinks.normalizedEvent.eventType',
            'eventLinks.normalizedEvent.eventSeverity',
            'eventLinks.normalizedEvent.asset',
        ]);

        $userIds = collect()
            ->push($incident->currentAssignment?->assigned_to_type === AssigneeType::User
                ? (int) $incident->currentAssignment->assigned_to_id
                : null)
            ->concat($incident->comments->map(fn ($comment) => (int) $comment->user_id))
            ->concat($incident->timeline
                ->filter(fn ($entry) => $entry->actor_type === TimelineActorType::User)
                ->map(fn ($entry) => (int) $entry->actor_id));

        $users = $this->resolveUsers($userIds);

        return response()->json($this->presenter->toDetail($incident, $users));
    }

    /**
     * Batch-load the users referenced by the given ids into an id-keyed map.
     *
     * @param  Collection<int, int|null>  $ids
     * @return Collection<int, User>
     */
    private function resolveUsers(Collection $ids): Collection
    {
        $ids = $ids->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }
}
