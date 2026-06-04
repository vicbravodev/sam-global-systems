<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\CreateManualIncident;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Actions\ReclassifyIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\ReclassifyIncidentRequest;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Http\Requests\Incidents\UpdateIncidentRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', Incident::class);

        $query = Incident::query()
            ->where('team_id', $current_team->id)
            ->with(['type', 'status', 'priority']);

        if ($request->filled('status')) {
            $statusCode = $request->string('status')->toString();
            $statusId = IncidentStatus::query()->where('code', $statusCode)->value('id');
            if ($statusId !== null) {
                $query->where('incident_status_id', $statusId);
            }
        }

        if ($request->boolean('open_only')) {
            $query->whereHas('status', fn ($q) => $q->where('is_terminal', false));
        }

        if ($request->filled('priority')) {
            $code = $request->string('priority')->toString();
            $priorityId = IncidentPriority::query()->where('code', $code)->value('id');
            if ($priorityId !== null) {
                $query->where('incident_priority_id', $priorityId);
            }
        }

        if ($request->filled('type')) {
            $code = $request->string('type')->toString();
            $typeId = IncidentType::query()->where('code', $code)->value('id');
            if ($typeId !== null) {
                $query->where('incident_type_id', $typeId);
            }
        }

        if ($request->filled('opened_after')) {
            $query->where('opened_at', '>=', $request->date('opened_after'));
        }

        if ($request->filled('opened_before')) {
            $query->where('opened_at', '<=', $request->date('opened_before'));
        }

        $incidents = $query->orderByDesc('opened_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($incidents);
    }

    public function show(Team $current_team, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $incident->load([
            'type',
            'status',
            'priority',
            'currentAssignment',
            'evidence',
            'eventLinks.normalizedEvent',
            'resolution',
            'timeline' => fn ($q) => $q->limit(50),
        ]);

        return response()->json(['data' => $incident]);
    }

    public function store(StoreIncidentRequest $request, Team $current_team, CreateManualIncident $create): JsonResponse
    {
        $this->authorize('create', Incident::class);

        $incident = $create->execute(
            teamId: $current_team->id,
            creator: $request->user(),
            data: $request->validated(),
        );

        return response()->json(['data' => $incident], 201);
    }

    public function update(UpdateIncidentRequest $request, Team $current_team, Incident $incident): JsonResponse
    {
        $this->authorize('update', $incident);

        $payload = $request->validated();
        if (array_key_exists('metadata', $payload)) {
            $payload['metadata_json'] = $payload['metadata'];
            unset($payload['metadata']);
        }

        $incident->update($payload);

        return response()->json(['data' => $incident->fresh(['type', 'status', 'priority'])]);
    }

    public function reclassify(
        ReclassifyIncidentRequest $request,
        Team $current_team,
        Incident $incident,
        ReclassifyIncident $reclassify,
    ): JsonResponse {
        $this->authorize('reclassify', $incident);

        $type = IncidentType::query()->findOrFail($request->validated('incident_type_id'));
        $priorityId = $request->validated('incident_priority_id');
        $priority = $priorityId !== null ? IncidentPriority::query()->find($priorityId) : null;

        $updated = $reclassify->execute(
            incident: $incident,
            newType: $type,
            newPriority: $priority,
            actorType: IncidentCreatorType::User,
            actorId: $request->user()->id,
        );

        return response()->json(['data' => $updated]);
    }

    public function escalate(Request $request, Team $current_team, Incident $incident, EscalateIncident $escalate): JsonResponse
    {
        $this->authorize('escalate', $incident);

        if ($incident->status?->code === IncidentStatusCode::Escalated->value) {
            return response()->json(['message' => 'Incident is already escalated.'], 422);
        }

        $reason = $request->string('reason')->toString() ?: null;

        $updated = $escalate->execute(
            incident: $incident,
            reason: $reason,
            escalatedByType: IncidentCreatorType::User,
            escalatedById: $request->user()->id,
        );

        return response()->json(['data' => $updated]);
    }

    public function reopen(Team $current_team, Incident $incident): JsonResponse
    {
        $this->authorize('reopen', $incident);

        if (! $incident->isTerminal()) {
            return response()->json(['message' => 'Incident is not in a terminal state.'], 422);
        }

        $openStatus = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->firstOrFail();

        $incident->update([
            'incident_status_id' => $openStatus->id,
            'resolved_at' => null,
            'closed_at' => null,
            'false_positive_at' => null,
            'cancelled_at' => null,
        ]);

        return response()->json(['data' => $incident->fresh(['status', 'priority', 'type'])]);
    }
}
