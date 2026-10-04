<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Models\AIEventEvaluation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué evaluaciones entran a la medición Clef vs GPT: sólo eventos de tipos
 * que hoy se evalúan con IA. Los que ya no pasan por la IA — porque una
 * regla de SAM establece el hecho (`ai.rule_resolved_event_types`) o porque
 * se saltan (`ai.skip_evaluation_event_types`, `ai.skip_evaluation_categories`)
 * — pueden tener evaluaciones viejas de GPT, pero medirlos o etiquetarlos
 * ensucia la comparación. Lo usan el backfill, el etiquetado y el reporte.
 */
final class ClefMeasurableEvaluations
{
    /**
     * @param  Builder<AIEventEvaluation>  $query
     * @return Builder<AIEventEvaluation>
     */
    public static function constrain(Builder $query): Builder
    {
        $excludedTypes = array_values(array_unique([
            ...(array) config('ai.rule_resolved_event_types', []),
            ...(array) config('ai.skip_evaluation_event_types', []),
        ]));
        $excludedCategories = array_values((array) config('ai.skip_evaluation_categories', []));

        return $query->whereHas('normalizedEvent', function (Builder $event) use ($excludedTypes, $excludedCategories): void {
            $event
                ->where(fn (Builder $q) => $q->whereNull('event_type_id')
                    ->orWhereHas('eventType', fn (Builder $type) => $type->whereNotIn('code', $excludedTypes)))
                ->where(fn (Builder $q) => $q->whereNull('event_category_id')
                    ->orWhereHas('eventCategory', fn (Builder $category) => $category->whereNotIn('code', $excludedCategories)));
        });
    }
}
