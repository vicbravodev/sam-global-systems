<?php

namespace App\Http\Controllers\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Support\AIEvaluationProgressBroadcast;
use App\Domains\AI\Support\AIStreamTaskRegistry;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Per-spec §9: Server-Sent-Events fallback for AI agent streaming when the
 * client cannot connect to Soketi. Tasks are registered in
 * `AIStreamTaskRegistry` (Valkey-backed cache) before the agent is invoked
 * and resolved here for tenant/user auth.
 */
class AIStreamController extends Controller
{
    public function stream(Request $request, Team $current_team, string $taskId): StreamedResponse
    {
        $payload = AIStreamTaskRegistry::resolve($taskId);

        if ($payload === null) {
            throw new NotFoundHttpException('AI streaming task not found or expired.');
        }

        if ((int) $payload['team_id'] !== $current_team->id) {
            throw new NotFoundHttpException('AI streaming task does not belong to this team.');
        }

        $user = $request->user();

        if ($user === null || (int) $user->id !== (int) $payload['user_id']) {
            throw new NotFoundHttpException('AI streaming task does not belong to this user.');
        }

        $evaluationId = $payload['evaluation_id'] ?? null;

        $evaluation = $evaluationId !== null
            ? AIEventEvaluation::withoutGlobalScopes()->find($evaluationId)
            : null;

        return new StreamedResponse(function () use ($taskId, $evaluation): void {
            $this->emit('start', [
                'task_id' => $taskId,
                'stage' => 'initializing',
                'evaluation_id' => $evaluation?->id,
            ]);

            if ($evaluation === null) {
                $this->emit('progress', (new AIEvaluationProgressBroadcast(
                    taskId: $taskId,
                    stage: 'pending',
                    chunk: 'Awaiting evaluation result.',
                    progressPct: 5,
                ))->broadcastWith());

                $this->emit('end', ['task_id' => $taskId, 'stage' => 'pending']);

                return;
            }

            $explanation = optional($evaluation->explanation)->summary ?? $evaluation->explanation_text ?? 'Evaluation complete.';

            $this->emit('progress', (new AIEvaluationProgressBroadcast(
                taskId: $taskId,
                stage: 'completed',
                chunk: (string) $explanation,
                progressPct: 100,
                evaluationId: $evaluation->id,
                normalizedEventId: $evaluation->normalized_event_id,
            ))->broadcastWith());

            $this->emit('end', [
                'task_id' => $taskId,
                'stage' => 'completed',
                'classification' => $evaluation->classification?->value,
                'evaluation_id' => $evaluation->id,
            ]);
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";

        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }
}
