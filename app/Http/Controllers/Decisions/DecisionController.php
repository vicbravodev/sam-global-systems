<?php

namespace App\Http\Controllers\Decisions;

use App\Domains\Decisions\Actions\OverrideDecision;
use App\Domains\Decisions\Models\Decision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Decisions\OverrideDecisionRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DecisionController extends Controller
{
    public function index(Request $request, Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', Decision::class);

        $query = Decision::query()
            ->where('team_id', $current_team->id)
            ->with('outcome');

        if ($request->filled('outcome_code')) {
            $query->where('decision_code', $request->string('outcome_code'));
        }

        if ($request->filled('normalized_event_id')) {
            $query->where('normalized_event_id', $request->integer('normalized_event_id'));
        }

        $decisions = $query->orderByDesc('decided_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($decisions);
    }

    public function show(Team $current_team, Decision $decision): JsonResponse
    {
        $this->authorize('view', $decision);

        $decision->load(['outcome', 'traces', 'overrides', 'escalationPolicy', 'aiEvaluation']);

        return response()->json(['data' => $decision]);
    }

    public function override(
        OverrideDecisionRequest $request,
        Team $current_team,
        Decision $decision,
        OverrideDecision $overrideDecision,
    ): JsonResponse {
        $this->authorize('override', $decision);

        $override = $overrideDecision->execute(
            $decision,
            $request->user(),
            (string) $request->input('new_outcome'),
            (string) $request->input('reason'),
        );

        return response()->json([
            'data' => $override->fresh(['decision', 'overriddenBy']),
        ], 201);
    }
}
