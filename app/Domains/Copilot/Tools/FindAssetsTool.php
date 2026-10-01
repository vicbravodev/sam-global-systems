<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Assets\Models\Asset;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotText;

/**
 * Looks units up by a loose piece of their code or name ("t5", "kenworth")
 * so the agent can pick the exact code before calling a unit tool.
 */
final class FindAssetsTool implements CopilotTool
{
    private const SCAN_LIMIT = 5000;

    private const DEFAULT_LIMIT = 5;

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('assets.view')) {
            return CopilotToolResult::denied('find_assets', 'Búsqueda de unidades', 'activos');
        }

        $query = (string) ($context->arguments['query'] ?? '');
        $words = preg_split('/\s+/', CopilotText::normalize($query));
        // Sólo se descartan los tokens vacíos: "0" es un fragmento de código válido.
        $tokens = array_values(array_filter(
            array_map(fn (string $token) => CopilotText::key($token), $words === false ? [] : $words),
            fn (string $token): bool => $token !== '',
        ));
        $whole = CopilotText::key($query);
        $limit = (int) ($context->arguments['limit'] ?? self::DEFAULT_LIMIT);

        $matches = $tokens === [] ? collect() : Asset::query()
            ->where('team_id', $context->teamId)
            ->when($context->category, fn ($q, $category) => $q->whereHas('assetType', fn ($t) => $t->where('category', $category->value)))
            ->with('assetType:id,category')
            ->orderBy('id')
            ->limit(self::SCAN_LIMIT)
            ->get(['id', 'team_id', 'asset_type_id', 'code', 'name', 'last_seen_at'])
            ->filter(function (Asset $asset) use ($tokens): bool {
                $haystack = CopilotText::key($asset->code).'|'.CopilotText::key($asset->name);

                foreach ($tokens as $token) {
                    if (! str_contains($haystack, $token)) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (Asset $asset) => [$this->rank($asset, $whole), CopilotText::key($asset->code)])
            ->values();

        $items = $matches->take($limit)->map(fn (Asset $asset) => [
            'code' => $asset->code,
            'name' => $asset->name,
            'category' => $asset->assetType?->category?->value,
            'lastSeenAt' => $asset->last_seen_at?->toIso8601String(),
        ])->all();

        if ($items === []) {
            $text = 'No encontré unidades que coincidan con la búsqueda.';

            return new CopilotToolResult(
                tool: 'find_assets',
                label: 'Búsqueda de unidades',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => $text]],
                facts: ['total' => 0, 'items' => []],
                highlights: [$text],
            );
        }

        return new CopilotToolResult(
            tool: 'find_assets',
            label: 'Búsqueda de unidades',
            facts: ['total' => $matches->count(), 'items' => $items],
            highlights: ["Encontré {$matches->count()} unidad(es): ".implode(', ', array_map(fn (array $i) => $i['code'] ?? $i['name'], $items)).'.'],
        );
    }

    /**
     * 0 = exact code, 1 = code starts with the query, 2 = anything else.
     */
    private function rank(Asset $asset, string $whole): int
    {
        $code = CopilotText::key($asset->code);

        return match (true) {
            $code !== '' && $code === $whole => 0,
            $code !== '' && $whole !== '' && str_starts_with($code, $whole) => 1,
            default => 2,
        };
    }
}
