<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;

/**
 * Header card of a unit: identity, status, driver, position and last signal.
 */
final class AssetSummaryTool implements CopilotTool
{
    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('assets.view')) {
            return CopilotToolResult::denied('asset_summary', 'Ficha de unidad', 'activos');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $asset->loadMissing(['assetType', 'latestLocation', 'latestTelemetry', 'provider', 'currentDriverAssignment.driver']);

        $location = $asset->latestLocation;
        $driver = $asset->currentDriverAssignment?->driver;
        $motion = CopilotPresenter::motionState($location);
        $lastSignal = collect([$location?->recorded_at, $asset->latestTelemetry?->recorded_at])->filter()->max();
        $category = $asset->assetType?->category;

        $card = [
            'type' => 'asset',
            'asset' => [
                'id' => (int) $asset->id,
                'code' => $asset->code,
                'name' => (string) $asset->name,
                'category' => $category?->value,
                'categoryLabel' => $category?->label(),
                'typeName' => $asset->assetType?->name,
                'status' => $asset->status->value,
                'statusLabel' => CopilotPresenter::STATUS_LABELS[$asset->status->value] ?? $asset->status->value,
                'motion' => $motion,
                'motionLabel' => CopilotPresenter::motionLabel($motion),
                'provider' => $asset->provider?->name,
                'driver' => $driver ? [
                    'id' => (int) $driver->id,
                    'name' => (string) $driver->full_name,
                    'href' => CopilotPresenter::driverHref($context->teamSlug, (int) $driver->id),
                ] : null,
                'location' => CopilotPresenter::location($location),
                'lastSignalAt' => $lastSignal?->toIso8601String(),
                'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $asset->id),
            ],
        ];

        $label = CopilotPresenter::assetLabel($asset);
        $highlights = [
            "{$label} está ".mb_strtolower(CopilotPresenter::motionLabel($motion))
                .($location?->formatted_location ? " en {$location->formatted_location}" : '')
                .'.',
        ];

        if ($driver) {
            $highlights[] = "Conductor asignado: {$driver->full_name}.";
        }

        return new CopilotToolResult(
            tool: 'asset_summary',
            label: 'Ficha de unidad',
            blocks: [$card],
            sources: [[
                'kind' => 'asset',
                'id' => (int) $asset->id,
                'label' => $label,
                'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $asset->id),
            ]],
            facts: [
                'asset' => $label,
                'category' => $category?->label(),
                'status' => $card['asset']['statusLabel'],
                'motion' => $card['asset']['motionLabel'],
                'driver' => $driver?->full_name,
                'last_signal' => $card['asset']['lastSignalAt'],
            ],
            highlights: $highlights,
        );
    }
}
