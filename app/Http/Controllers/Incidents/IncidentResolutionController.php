<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\ResolveIncidentRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentResolutionController extends Controller
{
    public function resolve(
        ResolveIncidentRequest $request,
        Team $current_team,
        Incident $incident,
        CloseIncident $closeIncident,
        #[CurrentUser] User $user,
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
            resolvedById: $user->id,
        );

        return response()->json(['data' => $resolution], 201);
    }

    public function close(Request $request, Team $current_team, Incident $incident, CloseIncident $closeIncident, #[CurrentUser] User $user): JsonResponse
    {
        $this->authorize('close', $incident);

        // Mismo criterio que el `?:` original: '' y '0' usan el resumen por defecto.
        $summary = $request->string('summary')->toString();

        $resolution = $closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::UnresolvedClosed,
            summary: in_array($summary, ['', '0'], true) ? 'Closed without further action.' : $summary,
            resolvedByType: IncidentCreatorType::User,
            resolvedById: $user->id,
        );

        return response()->json(['data' => $resolution], 200);
    }
}
