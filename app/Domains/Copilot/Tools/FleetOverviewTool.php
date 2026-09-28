<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Assets\Models\Asset;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Snapshot of the whole fleet (or one category of it): how many units are
 * moving, stopped or silent, and where each one is.
 */
final class FleetOverviewTool implements CopilotTool
{
    private const LIST_LIMIT = 12;

    private const MAP_LIMIT = 300;

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('assets.view')) {
            return CopilotToolResult::denied('fleet_overview', 'Estado de la flota', 'activos');
        }

        $query = Asset::query()
            ->where('team_id', $context->teamId)
            ->with(['assetType', 'latestLocation', 'currentDriverAssignment.driver']);

        if ($context->category !== null) {
            $query->whereHas('assetType', fn (Builder $q) => $q->where('category', $context->category->value));
        }

        $assets = $query->limit(2000)->get();

        $rows = $assets->map(function (Asset $asset) use ($context): array {
            $motion = CopilotPresenter::motionState($asset->latestLocation);

            return [
                'id' => (int) $asset->id,
                'code' => $asset->code,
                'name' => (string) $asset->name,
                'category' => $asset->assetType?->category->value,
                'status' => $asset->status->value,
                'statusLabel' => CopilotPresenter::STATUS_LABELS[$asset->status->value] ?? $asset->status->value,
                'motion' => $motion,
                'motionLabel' => CopilotPresenter::motionLabel($motion),
                'driverName' => $asset->currentDriverAssignment?->driver?->full_name,
                'location' => CopilotPresenter::location($asset->latestLocation),
                'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $asset->id),
            ];
        });

        $counts = $rows->countBy('motion');
        $alerts = $rows->whereIn('status', ['alert', 'critical'])->count();
        $scope = $context->category ? mb_strtolower($context->category->label()).'s' : 'unidades';

        $ordered = $rows
            ->sortBy(fn (array $row) => [
                in_array($row['status'], ['critical', 'alert'], true) ? 0 : 1,
                ['moving' => 0, 'stopped' => 1, 'no_signal' => 2][$row['motion']] ?? 3,
            ])
            ->values();

        $blocks = [
            [
                'type' => 'kpis',
                'items' => [
                    ['label' => ucfirst($scope), 'value' => $rows->count()],
                    ['label' => 'En ruta', 'value' => $counts->get('moving', 0), 'tone' => 'ok'],
                    ['label' => 'Detenidas', 'value' => $counts->get('stopped', 0)],
                    ['label' => 'Sin señal', 'value' => $counts->get('no_signal', 0), 'tone' => $counts->get('no_signal', 0) > 0 ? 'high' : null],
                    ['label' => 'En alerta', 'value' => $alerts, 'tone' => $alerts > 0 ? 'critical' : null],
                ],
            ],
        ];

        $positioned = $ordered->filter(fn (array $row) => $row['location'] !== null)->take(self::MAP_LIMIT);

        if ($positioned->isNotEmpty()) {
            $blocks[] = [
                'type' => 'fleet_map',
                'points' => $positioned->map(fn (array $row) => [
                    'id' => $row['id'],
                    'code' => $row['code'] ?? $row['name'],
                    'latitude' => $row['location']['latitude'],
                    'longitude' => $row['location']['longitude'],
                    'motion' => $row['motion'],
                    'status' => $row['status'],
                ])->values()->all(),
            ];
        }

        if ($rows->isNotEmpty()) {
            $blocks[] = [
                'type' => 'assets',
                'title' => ucfirst($scope).' · posición actual',
                'total' => $rows->count(),
                'items' => $ordered->take(self::LIST_LIMIT)->all(),
            ];
        }

        return new CopilotToolResult(
            tool: 'fleet_overview',
            label: 'Estado de la flota',
            blocks: $blocks,
            facts: [
                'scope' => $scope,
                'total' => $rows->count(),
                'moving' => $counts->get('moving', 0),
                'stopped' => $counts->get('stopped', 0),
                'no_signal' => $counts->get('no_signal', 0),
                'in_alert' => $alerts,
                'sample' => $ordered->take(8)->map(fn (array $r) => [
                    'unit' => $r['code'] ?? $r['name'],
                    'state' => $r['motionLabel'],
                    'where' => $r['location']['formattedLocation'] ?? null,
                ])->all(),
            ],
            highlights: [
                $this->fleetSentence($rows->count(), $scope, $counts->get('moving', 0), $counts->get('stopped', 0), $counts->get('no_signal', 0)),
                $alerts > 0 ? "{$alerts} en estado de alerta." : 'Ninguna en estado de alerta.',
            ],
        );
    }

    private function fleetSentence(int $total, string $scope, int $moving, int $stopped, int $silent): string
    {
        if ($total === 0) {
            return "No encontré {$scope} en tu flota.";
        }

        $noun = $total === 1
            ? (['unidades' => 'unidad', 'vehículos' => 'vehículo', 'remolques' => 'remolque'][$scope] ?? $scope)
            : $scope;

        return "Tienes {$total} {$noun}: {$moving} en ruta, {$stopped} "
            .($stopped === 1 ? 'detenida' : 'detenidas')
            ." y {$silent} sin señal reciente.";
    }
}
