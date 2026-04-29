<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\AssignIncidentRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class IncidentAssignmentController extends Controller
{
    public function store(
        AssignIncidentRequest $request,
        Team $current_team,
        Incident $incident,
        AssignIncident $assignIncident,
    ): JsonResponse {
        $this->authorize('assign', $incident);

        $assignment = $assignIncident->execute(
            incident: $incident,
            assigneeType: AssigneeType::from($request->validated('assigned_to_type')),
            assigneeId: (int) $request->validated('assigned_to_id'),
            role: $request->validated('role'),
            assignedByType: IncidentCreatorType::User,
            assignedById: $request->user()->id,
        );

        return response()->json(['data' => $assignment], 201);
    }
}
