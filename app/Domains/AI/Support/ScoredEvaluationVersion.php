<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Models\AIEventEvaluation;
use Illuminate\Support\Collection;

/**
 * Qué versión de la evaluación de un evento se califica al medir Clef vs
 * GPT: la que el operador etiquetó (si hay varias, la más reciente). Las
 * versiones posteriores a un veredicto ya lo vieron en
 * `recent_history.operator_feedback`, así que calificarlas favorecería a
 * GPT. Sin veredicto, la más reciente. El reporte y el backfill usan esta
 * misma regla para no medir ni gastar en versiones distintas.
 */
final class ScoredEvaluationVersion
{
    /**
     * @param  Collection<int, AIEventEvaluation>  $versions  versiones de UN evento, de la más reciente a la más antigua
     */
    public static function pick(Collection $versions): ?AIEventEvaluation
    {
        return $versions->first(fn (AIEventEvaluation $e): bool => $e->operator_verdict !== null) ?? $versions->first();
    }
}
