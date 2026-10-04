<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Support\ClefShadowGate;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * Despacha la medición en sombra con Clef de una evaluación oficial hecha
 * por GPT. Después del commit: `AIEvaluationCompleted` se emite dentro de la
 * transacción de la evaluación.
 */
class DispatchClefShadowEvaluation
{
    public function __construct(private readonly ClefShadowGate $gate) {}

    public function handle(AIEvaluationCompleted $event): void
    {
        $evaluation = $event->evaluation;
        $input = ['evaluation_id' => $evaluation->id];

        $reason = $this->gate->closedReason();

        if ($reason === null && ! in_array($evaluation->evaluation_mode, [EvaluationMode::AiText, EvaluationMode::Hybrid], true)) {
            $reason = 'rules_only_mode';
        }

        $rate = max(0.0, min(1.0, (float) config('ai.clef.sample_rate', 1.0)));
        $roll = mt_rand() / mt_getrandmax();

        if ($reason === null && $rate < 1.0 && $roll >= $rate) {
            $reason = 'not_sampled';
        }

        if ($reason !== null) {
            SystemLog::skipped(
                'ai.clef_shadow.skipped',
                reason: $reason,
                input: $input,
                calc: $reason === 'not_sampled' ? ['sample_rate' => $rate, 'roll' => round($roll, 4)] : null,
                debug: $reason === 'disabled',
            );

            return;
        }

        $teamId = $evaluation->team_id;
        $evaluationId = $evaluation->id;

        DB::afterCommit(function () use ($teamId, $evaluationId, $input): void {
            ShadowEvaluateWithClefJob::dispatch($teamId, $evaluationId);

            SystemLog::ok('ai.clef_shadow.dispatched', input: $input, result: ['models' => array_values((array) config('ai.clef.models', []))]);
        });
    }
}
