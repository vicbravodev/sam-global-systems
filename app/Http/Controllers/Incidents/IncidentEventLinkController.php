<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\LinkEventToIncident;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\LinkIncidentEventRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class IncidentEventLinkController extends Controller
{
    public function store(
        LinkIncidentEventRequest $request,
        Team $current_team,
        Incident $incident,
        LinkEventToIncident $link,
    ): JsonResponse {
        $this->authorize('linkEvent', $incident);

        $event = NormalizedEvent::withoutGlobalScopes()
            ->where('id', $request->validated('normalized_event_id'))
            ->where('team_id', $current_team->id)
            ->firstOrFail();

        $linkRecord = $link->execute(
            $incident,
            $event,
            EventRelationType::from($request->validated('relation_type')),
        );

        return response()->json(['data' => $linkRecord], 201);
    }
}
