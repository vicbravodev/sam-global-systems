<?php

namespace App\Http\Controllers\AI;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Http\PerPage;
use App\Support\SystemLog;
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
            if ($classification !== null) {
                $query->where('classification', $classification);
            }
        }

        if ($request->filled('normalized_event_id')) {
            $query->where('normalized_event_id', $request->integer('normalized_event_id'));
        }

        $evaluations = $query->orderByDesc('id')
            ->paginate(PerPage::from($request, 15));

        return response()->json($evaluations);
    }

    public function show(Team $current_team, AIEventEvaluation $evaluation): JsonResponse
    {
        $this->authorize('view', $evaluation);

        // Solo métricas operativas del log de inferencia: el snapshot de
        // entrada (prompt completo con contexto), la salida cruda y el costo
        // estimado son internos y no se exponen a los tenants.
        $evaluation->load([
            'explanation',
            'decisionSignals',
            'recommendedActions',
            'inferenceLogs' => fn ($query) => $query->select(['id', 'evaluation_id', 'status', 'latency_ms', 'tokens_used', 'media_assets_count', 'created_at']),
        ]);

        return response()->json(['data' => $evaluation]);
    }

    public function reevaluate(Request $request, Team $current_team, AIEventEvaluation $evaluation): JsonResponse
    {
        $this->authorize('reevaluate', $evaluation);

        $reason = $request->input('reason');

        // "Pedido", no "encolado": el job es único por (evento, trigger). Solo
        // se registra si hubo motivo, nunca su texto.
        SystemLog::ok(
            'ai.reevaluation.requested',
            input: [
                'normalized_event_id' => $evaluation->normalized_event_id,
                'evaluation_id' => $evaluation->id,
                'trigger_type' => ReevaluationTrigger::ManualReviewRequested->value,
                'requested_by' => 'operator',
                'reason_present' => is_string($reason) && $reason !== '',
            ],
            calc: ['debounce_s' => 0],
        );

        ReevaluateEventJob::dispatch(
            $evaluation->normalized_event_id,
            ReevaluationTrigger::ManualReviewRequested->value,
            $evaluation->id,
            is_string($reason) ? $reason : null,
        );

        return response()->json([
            'message' => 'Reevaluación encolada',
            'normalized_event_id' => $evaluation->normalized_event_id,
            'trigger' => ReevaluationTrigger::ManualReviewRequested->value,
        ], 202);
    }
}
