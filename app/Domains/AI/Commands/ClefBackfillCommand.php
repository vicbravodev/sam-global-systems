<?php

namespace App\Domains\AI\Commands;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\AI\Support\ClefShadowGate;
use App\Domains\AI\Support\ScoredEvaluationVersion;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Evalúa con Clef, en sombra, evaluaciones pasadas de GPT usando el contexto
 * exacto que vio GPT. Las que tienen veredicto humano van primero: son las
 * que permiten medir. Cruza tenants a propósito (trabajo de plataforma).
 */
class ClefBackfillCommand extends Command
{
    protected $signature = 'ai:clef-backfill
        {--team= : Sólo este team id}
        {--since= : Sólo evaluaciones desde esta fecha (Y-m-d)}
        {--limit=500 : Máximo de evaluaciones}
        {--sync : Ejecutar en este proceso en lugar de encolar}
        {--force : No pedir confirmación}';

    protected $description = 'Evalúa con Clef, en sombra, evaluaciones pasadas de GPT (las que tienen veredicto primero)';

    public function handle(ClefShadowGate $gate): int
    {
        $reason = $gate->closedReason(requireWindow: false);

        if ($reason !== null) {
            $this->error('No se puede lanzar: '.$reason);

            return self::FAILURE;
        }

        /** @var list<string> $models */
        $models = array_values((array) config('ai.clef.models', []));
        $team = $this->option('team') !== null ? (int) $this->option('team') : null;
        $since = $this->option('since') !== null ? Carbon::parse($this->option('since'))->startOfDay() : null;
        $limit = max(1, (int) $this->option('limit'));

        // Sólo la versión que el reporte califica (ScoredEvaluationVersion):
        // gastar en versiones superadas no aporta a la medición.
        /** @var Collection<int, AIEventEvaluation> $evaluations */
        $evaluations = TenantContext::withoutTenant(fn () => AIEventEvaluation::query()
            ->when($team !== null, fn (Builder $q) => $q->where('team_id', $team))
            ->when($since !== null, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->whereIn('evaluation_mode', [EvaluationMode::AiText, EvaluationMode::Hybrid])
            ->whereHas('inferenceLogs')
            ->orderByDesc('evaluation_version')
            ->orderByDesc('id')
            ->get(['id', 'team_id', 'normalized_event_id', 'evaluation_version', 'operator_verdict'])
            ->groupBy('normalized_event_id')
            ->map(fn (Collection $versions): ?AIEventEvaluation => ScoredEvaluationVersion::pick($versions))
            ->filter()
            ->values());

        $settled = TenantContext::withoutTenant(fn () => AIShadowEvaluation::query()
            ->whereIn('ai_event_evaluation_id', $evaluations->pluck('id'))
            ->where('schema_version', ClefQuestionSchema::VERSION)
            ->settled()
            ->get(['ai_event_evaluation_id', 'model'])
            ->groupBy('ai_event_evaluation_id')
            ->map(fn (Collection $rows): array => $rows->pluck('model')->all()));

        $evaluations = $evaluations
            ->filter(fn (AIEventEvaluation $e): bool => array_diff($models, $settled->get($e->id, [])) !== [])
            ->sortBy(fn (AIEventEvaluation $e): array => [$e->operator_verdict === null ? 1 : 0, -$e->id])
            ->take($limit)
            ->values();

        $tokens = (int) AIInferenceLog::query()->whereIn('evaluation_id', $evaluations->pluck('id'))->sum('input_tokens');
        $prices = (array) config('ai.clef.pricing_per_million_input', []);
        $pricePerMillion = array_sum(array_map(fn (string $m): float => (float) ($prices[$m] ?? 0.0), $models));
        $cost = round($tokens * $pricePerMillion / 1_000_000, 5);

        $this->info(sprintf(
            '%d evaluaciones · %d tokens de entrada · modelos %s · costo estimado US$%.4f',
            $evaluations->count(),
            $tokens,
            implode(', ', $models),
            $cost,
        ));

        SystemLog::ok(
            'ai.clef_backfill.planned',
            input: ['team_id' => $team, 'since' => $since?->toDateString(), 'limit' => $limit],
            calc: [
                'evaluations' => $evaluations->count(),
                'input_tokens' => $tokens,
                'models' => $models,
                'price_per_million_sum' => $pricePerMillion,
                'estimated_cost' => $cost,
            ],
        );

        if ($evaluations->isEmpty() || (! $this->option('force') && ! $this->confirm('¿Lanzar el backfill?'))) {
            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($evaluations as $evaluation) {
            $job = new ShadowEvaluateWithClefJob($evaluation->team_id, $evaluation->id, AIShadowEvaluation::SOURCE_BACKFILL);

            if (! $this->option('sync')) {
                dispatch($job);

                continue;
            }

            // En --sync no hay cola que reintente: un 429/5xx no debe cortar el
            // resto. El modelo queda sin respuesta definitiva y el siguiente
            // backfill lo vuelve a intentar.
            try {
                dispatch_sync($job);
            } catch (Throwable $e) {
                $failed++;
                SystemLog::degraded('ai.clef_backfill.job_failed', reason: 'transient_error', input: ['evaluation_id' => $evaluation->id], error: $e);
            }
        }

        if ($failed > 0) {
            $this->warn(sprintf('%d con error transitorio: corre el backfill otra vez para reintentarlos.', $failed));
        }

        return self::SUCCESS;
    }
}
