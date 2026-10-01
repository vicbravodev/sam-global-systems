<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\AcknowledgeIncident;
use App\Domains\Incidents\Actions\ClaimIncident;
use App\Domains\Incidents\Actions\CreateManualIncident;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Actions\ReclassifyIncident;
use App\Domains\Incidents\Actions\ReleaseIncident;
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
use App\Models\User;
use App\Support\Http\PerPage;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
            ->paginate(PerPage::from($request, 25));

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

    public function store(StoreIncidentRequest $request, Team $current_team, CreateManualIncident $create, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorize('create', Incident::class);

        $incident = $create->execute(
            teamId: $current_team->id,
            creator: $user,
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
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->authorize('reclassify', $incident);

        $type = IncidentType::query()->findOrFail($request->integer('incident_type_id'));
        $priority = $request->validated('incident_priority_id') !== null
            ? IncidentPriority::query()->find($request->integer('incident_priority_id'))
            : null;

        $updated = $reclassify->execute(
            incident: $incident,
            newType: $type,
            newPriority: $priority,
            actorType: IncidentCreatorType::User,
            actorId: $user->id,
        );

        return response()->json(['data' => $updated]);
    }

    public function acknowledge(Team $current_team, Incident $incident, AcknowledgeIncident $acknowledge, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorize('update', $incident);

        $updated = $acknowledge->execute($incident, $user->id);

        return response()->json(['data' => $updated]);
    }

    /**
     * Toma humana del incidente. El 409 distingue «llegaste tarde» de un
     * fallo de permisos: el incidente existe y el usuario puede gestionarlo,
     * pero otro monitorista ganó la carrera.
     */
    public function claim(Team $current_team, Incident $incident, ClaimIncident $claim, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorize('update', $incident);

        if (! $claim->execute($incident, $user)) {
            return response()->json(
                ['message' => 'Otro monitorista ya tomó este incidente.'],
                SymfonyResponse::HTTP_CONFLICT,
            );
        }

        return response()->json(['data' => $incident->fresh()]);
    }

    public function release(Team $current_team, Incident $incident, ReleaseIncident $release, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorize('update', $incident);

        if (! $release->execute($incident, $user)) {
            return response()->json(
                ['message' => 'Este incidente lo tiene otro monitorista.'],
                SymfonyResponse::HTTP_CONFLICT,
            );
        }

        return response()->json(['data' => $incident->fresh()]);
    }

    public function escalate(Request $request, Team $current_team, Incident $incident, EscalateIncident $escalate, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorize('escalate', $incident);

        if ($incident->status?->code === IncidentStatusCode::Escalated->value) {
            return response()->json(['message' => 'El incidente ya está escalado.'], 422);
        }

        $reason = $request->string('reason')->toString() ?: null;

        $updated = $escalate->execute(
            incident: $incident,
            reason: $reason,
            escalatedByType: IncidentCreatorType::User,
            escalatedById: $user->id,
        );

        return response()->json(['data' => $updated]);
    }

    public function reopen(Team $current_team, Incident $incident): JsonResponse
    {
        $this->authorize('reopen', $incident);

        if (! $incident->isTerminal()) {
            return response()->json(['message' => 'El incidente no está en un estado terminal.'], 422);
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
