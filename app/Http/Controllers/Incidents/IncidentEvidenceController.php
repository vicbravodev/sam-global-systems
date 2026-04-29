<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Incidents\Actions\AddIncidentEvidence;
use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Models\Incident;
use App\Http\Controllers\Controller;
use App\Http\Requests\Incidents\StoreIncidentEvidenceRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class IncidentEvidenceController extends Controller
{
    public function store(
        StoreIncidentEvidenceRequest $request,
        Team $current_team,
        Incident $incident,
        AddIncidentEvidence $addEvidence,
    ): JsonResponse {
        $this->authorize('attachEvidence', $incident);

        $evidence = $addEvidence->execute(
            incident: $incident,
            evidenceType: EvidenceType::from($request->validated('evidence_type')),
            sourceType: EvidenceSourceType::from($request->validated('source_type')),
            sourceReferenceId: $request->validated('source_reference_id'),
            title: $request->validated('title'),
            description: $request->validated('description'),
            metadata: $request->validated('metadata'),
            file: $request->file('file'),
            addedByType: IncidentCreatorType::User,
            addedById: $request->user()->id,
        );

        return response()->json(['data' => $evidence], 201);
    }
}
