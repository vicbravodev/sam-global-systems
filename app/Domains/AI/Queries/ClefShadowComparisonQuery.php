<?php

namespace App\Domains\AI\Queries;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use App\Support\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Métricas de la medición Clef vs GPT. Una fila por evento (última versión
 * de su evaluación) para no contar dos veces las reevaluaciones.
 *
 * - recall_real (seguridad): de los `confirmed`, % que el modelo dejó accionable (real_event/unclear).
 * - discard_correct (ahorro): de los `false_positive`, % que el modelo descartó.
 * - strict_accuracy: OperatorVerdict::agreesWith().
 * - brier: calibración de P(real_event) contra el veredicto (sólo Clef).
 */
class ClefShadowComparisonQuery
{
    public const int MIN_SAMPLE = 30;

    /**
     * @return array<string, array<string, array<string, float|int|null>>>
     */
    public function execute(?int $teamId, DateTimeInterface $since, bool $byEventType = false): array
    {
        $load = function () use ($teamId, $since): Collection {
            $evaluations = AIEventEvaluation::query()
                ->with('normalizedEvent.eventType')
                ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
                ->whereIn('evaluation_mode', [EvaluationMode::AiText, EvaluationMode::Hybrid])
                ->where('created_at', '>=', $since)
                ->orderByDesc('evaluation_version')
                ->orderByDesc('id')
                ->get()
                ->unique('normalized_event_id')
                ->values();

            $ids = $evaluations->pluck('id');

            $shadows = AIShadowEvaluation::query()
                ->when($teamId !== null, fn ($q) => $q->where('team_id', $teamId))
                ->whereIn('ai_event_evaluation_id', $ids)
                ->where('schema_version', ClefQuestionSchema::VERSION)
                ->get()
                ->groupBy('ai_event_evaluation_id');

            $logs = AIInferenceLog::query()
                ->whereIn('evaluation_id', $ids)
                ->orderBy('id')
                ->get(['evaluation_id', 'latency_ms', 'cost_estimate'])
                ->keyBy('evaluation_id');

            return $evaluations->map(fn (AIEventEvaluation $e): array => [
                'evaluation' => $e,
                'type' => $e->normalizedEvent?->eventType?->code ?? 'desconocido',
                'gpt_log' => $logs->get($e->id),
                'shadows' => $shadows->get($e->id, collect()),
            ]);
        };

        /** @var Collection<int, array{evaluation: AIEventEvaluation, type: string, gpt_log: ?AIInferenceLog, shadows: Collection<int, AIShadowEvaluation>}> $rows */
        $rows = $teamId !== null ? TenantContext::for($teamId, $load) : TenantContext::withoutTenant($load);

        $buckets = ['all' => $rows];

        if ($byEventType) {
            foreach ($rows->groupBy('type') as $type => $group) {
                $buckets[(string) $type] = $group;
            }
        }

        /** @var list<string> $models */
        $models = array_values((array) config('ai.clef.models', ['clef', 'clef-flash']));

        $report = [];

        foreach ($buckets as $bucket => $bucketRows) {
            $report[$bucket] = ['gpt' => $this->metricsForGpt($bucketRows)];

            foreach ($models as $model) {
                $report[$bucket][$model] = $this->metricsForModel($bucketRows, $model);
            }
        }

        return $report;
    }

    /**
     * @param  Collection<int, array{evaluation: AIEventEvaluation, type: string, gpt_log: ?AIInferenceLog, shadows: Collection<int, AIShadowEvaluation>}>  $rows
     * @return array<string, float|int|null>
     */
    private function metricsForGpt(Collection $rows): array
    {
        $items = $rows->map(fn (array $r): array => [
            'classification' => $r['evaluation']->classification,
            'verdict' => $r['evaluation']->operator_verdict,
            'gpt' => $r['evaluation']->classification,
            'p_real' => null,
            'latency' => $r['gpt_log']?->latency_ms,
            'cost' => $r['gpt_log']?->cost_estimate !== null ? (float) $r['gpt_log']->cost_estimate : null,
        ]);

        return $this->metrics($items, failed: 0);
    }

    /**
     * @param  Collection<int, array{evaluation: AIEventEvaluation, type: string, gpt_log: ?AIInferenceLog, shadows: Collection<int, AIShadowEvaluation>}>  $rows
     * @return array<string, float|int|null>
     */
    private function metricsForModel(Collection $rows, string $model): array
    {
        $failed = 0;
        $items = collect();

        foreach ($rows as $r) {
            $shadow = $r['shadows']->firstWhere('model', $model);

            if ($shadow === null) {
                continue;
            }

            if ($shadow->status !== AIShadowEvaluation::STATUS_SUCCESS) {
                $failed++;

                continue;
            }

            $probabilities = (array) $shadow->classification_probabilities_json;

            $items->push([
                'classification' => EventClassification::tryFrom((string) $shadow->classification),
                'verdict' => $r['evaluation']->operator_verdict,
                'gpt' => $r['evaluation']->classification,
                'p_real' => (float) ($probabilities['real_event'] ?? 0.0),
                'latency' => $shadow->latency_ms,
                'cost' => $shadow->cost_estimate,
            ]);
        }

        return $this->metrics($items, $failed);
    }

    /**
     * @param  Collection<int, array{classification: ?EventClassification, verdict: ?OperatorVerdict, gpt: ?EventClassification, p_real: ?float, latency: ?int, cost: ?float}>  $items
     * @return array<string, float|int|null>
     */
    private function metrics(Collection $items, int $failed): array
    {
        $ratio = fn (int $hits, int $n): ?float => $n > 0 ? round($hits / $n, 4) : null;
        $dismissive = [EventClassification::FalsePositive, EventClassification::Noise, EventClassification::Duplicate];

        $verdicted = $items->filter(fn (array $i): bool => $i['verdict'] !== null);
        $real = $verdicted->filter(fn (array $i): bool => $i['verdict'] === OperatorVerdict::Confirmed);
        $fp = $verdicted->filter(fn (array $i): bool => $i['verdict'] === OperatorVerdict::FalsePositive);
        $calibrated = $verdicted->filter(fn (array $i): bool => $i['p_real'] !== null);
        $latencies = $items->pluck('latency')->filter(fn (mixed $v): bool => $v !== null)->sort()->values();
        $costs = $items->pluck('cost')->filter(fn (mixed $v): bool => $v !== null);

        return [
            'n' => $items->count(),
            'agree_with_gpt' => $ratio($items->filter(fn (array $i): bool => $i['classification'] === $i['gpt'])->count(), $items->count()),
            'verdict_n' => $verdicted->count(),
            'real_n' => $real->count(),
            'recall_real' => $ratio($real->filter(fn (array $i): bool => $i['classification']?->isActionable() === true)->count(), $real->count()),
            'fp_n' => $fp->count(),
            'discard_correct' => $ratio($fp->filter(fn (array $i): bool => in_array($i['classification'], $dismissive, true))->count(), $fp->count()),
            'strict_accuracy' => $ratio($verdicted->filter(fn (array $i): bool => $i['verdict'] instanceof OperatorVerdict && $i['verdict']->agreesWith($i['classification']))->count(), $verdicted->count()),
            'brier' => $calibrated->isEmpty() ? null : round((float) $calibrated->avg(fn (array $i): float => ((float) $i['p_real'] - ($i['verdict'] === OperatorVerdict::Confirmed ? 1.0 : 0.0)) ** 2), 4),
            'cost_total' => round((float) $costs->sum(), 5),
            'cost_avg' => $costs->isEmpty() ? null : round((float) $costs->avg(), 5),
            'latency_p50' => $this->percentile($latencies, 0.5),
            'latency_p95' => $this->percentile($latencies, 0.95),
            'failed' => $failed,
        ];
    }

    /**
     * Percentil por rango más cercano sobre una lista ordenada.
     *
     * @param  Collection<int, mixed>  $sorted
     */
    private function percentile(Collection $sorted, float $p): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $index = (int) min($sorted->count() - 1, max(0, (int) ceil($p * $sorted->count()) - 1));

        return (int) $sorted[$index];
    }
}
