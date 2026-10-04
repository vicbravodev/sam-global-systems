<?php

namespace App\Domains\AI\Commands;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Domains\AI\Support\ClefShadowGate;
use App\Infrastructure\AI\Clef\ClefQuestionSchema;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

        /** @var Collection<int, AIEventEvaluation> $evaluations */
        $evaluations = TenantContext::withoutTenant(fn () => AIEventEvaluation::query()
            ->when($team !== null, fn (Builder $q) => $q->where('team_id', $team))
            ->when($since !== null, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->whereIn('evaluation_mode', [EvaluationMode::AiText, EvaluationMode::Hybrid])
            ->whereHas('inferenceLogs')
            ->where(function (Builder $q) use ($models): void {
                foreach ($models as $model) {
                    $q->orWhereDoesntHave('shadowEvaluations', fn (Builder $s) => $s->where('model', $model)->where('schema_version', ClefQuestionSchema::VERSION));
                }
            })
            ->orderByRaw('operator_verdict is null')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'team_id']));

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

        foreach ($evaluations as $evaluation) {
            $job = new ShadowEvaluateWithClefJob($evaluation->team_id, $evaluation->id, AIShadowEvaluation::SOURCE_BACKFILL);

            if ($this->option('sync')) {
                dispatch_sync($job);
            } else {
                dispatch($job);
            }
        }

        return self::SUCCESS;
    }
}
