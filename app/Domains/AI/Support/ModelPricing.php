<?php

namespace App\Domains\AI\Support;

use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Estimates the USD cost of an inference from the token counts reported by
 * the provider and the per-model price table in `config('ai.pricing')`
 * (USD per 1M tokens). Providers return versioned model ids (e.g.
 * `gpt-5.4-2026-05-13`), so lookup falls back to the longest configured
 * prefix — giving `gpt-5.4-nano` precedence over `gpt-5.4`. Unknown or
 * missing models cost 0.0 rather than failing the evaluation.
 */
final class ModelPricing
{
    private const TOKENS_PER_PRICE_UNIT = 1_000_000;

    public function estimateCost(?string $model, int $inputTokens, int $outputTokens): float
    {
        $entry = $this->resolveEntry($model);

        if ($entry === null) {
            return 0.0;
        }

        return round(
            ($inputTokens / self::TOKENS_PER_PRICE_UNIT) * (float) ($entry['input'] ?? 0.0)
                + ($outputTokens / self::TOKENS_PER_PRICE_UNIT) * (float) ($entry['output'] ?? 0.0),
            6,
        );
    }

    /**
     * Cost of a full text usage: since laravel/ai 1.0 `inputTokens` includes
     * cached tokens, so those are split out and billed at `cached_input`
     * (default: a tenth of the input rate, OpenAI's cached discount).
     */
    public function estimateUsageCost(?string $model, TextUsage $usage): float
    {
        $entry = $this->resolveEntry($model);

        if ($entry === null) {
            return 0.0;
        }

        $input = (float) ($entry['input'] ?? 0.0);
        $cached = (float) ($entry['cached_input'] ?? $input / 10);
        $cachedTokens = $usage->cacheReadInputTokens ?? 0;

        return round(
            ($usage->uncachedInputTokens() / self::TOKENS_PER_PRICE_UNIT) * $input
                + ($cachedTokens / self::TOKENS_PER_PRICE_UNIT) * $cached
                + ($usage->outputTokens / self::TOKENS_PER_PRICE_UNIT) * (float) ($entry['output'] ?? 0.0),
            6,
        );
    }

    /**
     * @return array{input?: float|int, output?: float|int, cached_input?: float|int}|null
     */
    private function resolveEntry(?string $model): ?array
    {
        $model = strtolower(trim((string) $model));

        if ($model === '') {
            return null;
        }

        /**
         * Las claves vienen del config: un id de modelo puramente numérico
         * llega como int, por eso se castea a string al comparar prefijos.
         *
         * @var array<array-key, array{input?: float|int, output?: float|int, cached_input?: float|int}> $pricing
         */
        $pricing = config('ai.pricing', []);

        if (isset($pricing[$model])) {
            return $pricing[$model];
        }

        $bestMatch = null;
        $bestLength = 0;

        foreach ($pricing as $configuredModel => $entry) {
            $prefix = strtolower((string) $configuredModel);

            if (str_starts_with($model, $prefix) && strlen($prefix) > $bestLength) {
                $bestMatch = $entry;
                $bestLength = strlen($prefix);
            }
        }

        return $bestMatch;
    }
}
