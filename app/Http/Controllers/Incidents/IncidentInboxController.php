<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentInboxPresenter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
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

    public function index(Team $current_team): Response
    {
        $this->authorize('viewAny', Incident::class);

        /** @var EloquentCollection<int, Incident> $incidents */
        $incidents = Incident::query()
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
            ])
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
        ]);
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
