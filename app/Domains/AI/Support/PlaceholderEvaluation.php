<?php

namespace App\Domains\AI\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Recognises evaluations produced by the deterministic stand-in agent
 * (`NullEventEvaluationAgent`, model `null-agent:*`). They carry a fixed
 * confidence (0.85) that is not a real verdict, so every screen must show
 * them as "Sin evaluación IA" and every precision metric must leave them out.
 */
final class PlaceholderEvaluation
{
    public const MODEL_PREFIX = 'null-agent';

    public const LABEL = 'Sin evaluación IA';

    public static function isPlaceholderModel(?string $model): bool
    {
        return $model !== null && str_starts_with(strtolower(trim($model)), self::MODEL_PREFIX);
    }

    /**
     * Restricts a query over `ai_event_evaluations` to real model verdicts.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function excludeFrom(Builder $query, string $column = 'model_used'): Builder
    {
        $query->where(fn (Builder $inner) => $inner
            ->whereNull($column)
            ->orWhere($column, 'not like', self::MODEL_PREFIX.'%'));

        return $query;
    }
}
