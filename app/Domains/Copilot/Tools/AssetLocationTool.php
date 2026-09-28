<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;

/**
 * Where a unit is right now, plus its recent trail.
 */
final class AssetLocationTool implements CopilotTool
{
    private const TRAIL_LIMIT = 30;

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('assets.view')) {
            return CopilotToolResult::denied('asset_location', 'Ubicación GPS', 'activos');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $trail = AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('recorded_at', '>=', $context->period->from)
            ->orderByDesc('recorded_at')
            ->limit(self::TRAIL_LIMIT)
            ->get();

        $latest = AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $label = CopilotPresenter::assetLabel($asset);

        if ($latest === null) {
            return new CopilotToolResult(
                tool: 'asset_location',
                label: 'Ubicación GPS',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => "{$label} todavía no reportó ninguna posición GPS."]],
                facts: ['asset' => $label, 'location' => null],
                highlights: ["{$label} no tiene posiciones GPS registradas."],
            );
        }

        $location = CopilotPresenter::location($latest);
        $motion = CopilotPresenter::motionState($latest);
        $maxSpeed = $trail->max(fn (AssetLocationSnapshot $s) => (float) $s->speed);

        $block = [
            'type' => 'location',
            'assetId' => (int) $asset->id,
            'assetLabel' => $label,
            'motion' => $motion,
            'motionLabel' => CopilotPresenter::motionLabel($motion),
            ...$location,
            'maxSpeed' => $maxSpeed !== null ? round((float) $maxSpeed, 1) : null,
            'trail' => $trail->reverse()->values()->map(fn (AssetLocationSnapshot $s) => [
                'latitude' => (float) $s->latitude,
                'longitude' => (float) $s->longitude,
                'speed' => $s->speed !== null ? (float) $s->speed : null,
                'recordedAt' => $s->recorded_at->toIso8601String(),
            ])->all(),
            'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $asset->id),
        ];

        $where = $latest->formatted_location ?: sprintf('%.5f, %.5f', $location['latitude'], $location['longitude']);
        $speed = $location['speed'] !== null ? " a {$location['speed']} km/h" : '';

        return new CopilotToolResult(
            tool: 'asset_location',
            label: 'Ubicación GPS',
            blocks: [$block],
            sources: [[
                'kind' => 'asset',
                'id' => (int) $asset->id,
                'label' => $label,
                'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $asset->id),
            ]],
            facts: [
                'asset' => $label,
                'location' => $where,
                'coordinates' => [$location['latitude'], $location['longitude']],
                'speed_kph' => $location['speed'],
                'recorded_at' => $location['recordedAt'],
                'motion' => $block['motionLabel'],
            ],
            highlights: [
                "Última posición de {$label}: {$where}{$speed}, ".CopilotPresenter::describeAge($location['recordedAt']).'.',
            ],
        );
    }
}
