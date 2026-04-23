<?php

namespace App\Http\Controllers\AI;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIEvaluationController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', AIEventEvaluation::class);

        $query = AIEventEvaluation::where('team_id', $current_team->id)
            ->with('explanation');

        if ($request->filled('classification')) {
            $classification = EventClassification::tryFrom($request->input('classification'));
            if ($classification) {
                $query->where('classification', $classification);
            }
        }

        if ($request->filled('normalized_event_id')) {
            $query->where('normalized_event_id', $request->integer('normalized_event_id'));
        }

        $evaluations = $query->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json($evaluations);
    }

    public function show(Team $current_team, AIEventEvaluation $evaluation): JsonResponse
    {
        $this->authorize('view', $evaluation);

        $evaluation->load(['explanation', 'decisionSignals', 'recommendedActions', 'inferenceLogs']);

        return response()->json(['data' => $evaluation]);
    }

    public function reevaluate(Request $request, Team $current_team, AIEventEvaluation $evaluation): JsonResponse
    {
        $this->authorize('reevaluate', $evaluation);

        $reason = $request->input('reason');

        ReevaluateEventJob::dispatch(
            $evaluation->normalized_event_id,
            ReevaluationTrigger::ManualReviewRequested->value,
            $evaluation->id,
            is_string($reason) ? $reason : null,
        );

        return response()->json([
            'message' => 'Reevaluation dispatched',
            'normalized_event_id' => $evaluation->normalized_event_id,
            'trigger' => ReevaluationTrigger::ManualReviewRequested->value,
        ], 202);
    }
}
