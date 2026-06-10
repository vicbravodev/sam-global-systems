<?php

namespace App\Domains\AI\Support;

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
     * @return array{input?: float|int, output?: float|int}|null
     */
    private function resolveEntry(?string $model): ?array
    {
        $model = strtolower(trim((string) $model));

        if ($model === '') {
            return null;
        }

        /** @var array<string, array{input?: float|int, output?: float|int}> $pricing */
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
