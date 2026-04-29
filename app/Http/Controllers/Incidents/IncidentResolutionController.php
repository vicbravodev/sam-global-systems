<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\ResolveIncidentRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentResolutionController extends Controller
{
    public function resolve(
        ResolveIncidentRequest $request,
        Team $current_team,
        Incident $incident,
        CloseIncident $closeIncident,
    ): JsonResponse {
        $this->authorize('resolve', $incident);

        $resolution = $closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::from($request->validated('resolution_code')),
            summary: $request->validated('summary'),
            rootCause: $request->validated('root_cause'),
            correctiveAction: $request->validated('corrective_action'),
            preventiveAction: $request->validated('preventive_action'),
            resolvedByType: IncidentCreatorType::User,
            resolvedById: $request->user()->id,
        );

        return response()->json(['data' => $resolution], 201);
    }

    public function close(Request $request, Team $current_team, Incident $incident, CloseIncident $closeIncident): JsonResponse
    {
        $this->authorize('close', $incident);

        $resolution = $closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::UnresolvedClosed,
            summary: $request->string('summary')->toString() ?: 'Closed without further action.',
            resolvedByType: IncidentCreatorType::User,
            resolvedById: $request->user()->id,
        );

        return response()->json(['data' => $resolution], 200);
    }
}
