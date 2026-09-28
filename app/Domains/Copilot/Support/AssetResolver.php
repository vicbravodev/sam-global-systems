<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Models\Asset;

/**
 * Finds which of the tenant's assets a free-text question is about
 * ("dame el reporte de la unidad T555", "¿dónde está el remolque R-12?").
 *
 * Matching is done against the tenant's own codes and names, never against
 * provider ids, and always inside an explicit `team_id` filter.
 */
final class AssetResolver
{
    /**
     * Upper bound on the fleet scanned per question. Large enough for any
     * realistic tenant, small enough to keep the lookup cheap.
     */
    private const SCAN_LIMIT = 5000;

    public function resolveById(int $teamId, ?int $assetId): ?Asset
    {
        if ($assetId === null) {
            return null;
        }

        return Asset::query()->where('team_id', $teamId)->whereKey($assetId)->first();
    }

    public function resolveFromPrompt(int $teamId, string $prompt): ?Asset
    {
        $normalized = CopilotText::normalize($prompt);
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return null;
        }

        // Every contiguous run of up to three tokens, glued: "t 555" → "t555".
        $candidates = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $glued = '';

            for ($j = $i; $j < min($count, $i + 3); $j++) {
                $glued .= $tokens[$j];
                $candidates[$glued] = true;
            }
        }

        $compactPrompt = CopilotText::key($prompt);

        $best = null;
        $bestScore = 0;

        Asset::query()
            ->where('team_id', $teamId)
            ->select(['id', 'team_id', 'code', 'name', 'asset_type_id', 'status', 'last_seen_at'])
            ->orderBy('id')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->each(function (Asset $asset) use ($candidates, $compactPrompt, $normalized, &$best, &$bestScore): void {
                $score = 0;
                $code = CopilotText::key($asset->code);

                if ($code !== '' && isset($candidates[$code])) {
                    $score = 100 + strlen($code);
                } elseif ($code !== '' && strlen($code) >= 4 && str_contains($compactPrompt, $code)) {
                    $score = 60 + strlen($code);
                }

                $name = CopilotText::normalize((string) $asset->name);

                if ($score === 0 && strlen($name) >= 3 && preg_match('/\b'.preg_quote($name, '/').'\b/u', $normalized)) {
                    $score = 40 + strlen($name);
                }

                if ($score > $bestScore) {
                    $best = $asset;
                    $bestScore = $score;
                }
            });

        return $best;
    }

    /**
     * Category the question is restricted to, if the operator named one
     * ("¿dónde están mis remolques?").
     */
    public function categoryFromPrompt(string $prompt): ?AssetCategory
    {
        $normalized = CopilotText::normalize($prompt);

        if (preg_match('/\b(remolques?|cajas?|trailers?|semirremolques?|plataformas?)\b/u', $normalized)) {
            return AssetCategory::Trailer;
        }

        if (preg_match('/\b(camion(es)?|tractos?|tractor(es)?|unidades|vehiculos?|pipas?)\b/u', $normalized)) {
            return AssetCategory::Vehicle;
        }

        return null;
    }
}
