<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;

/**
 * Fuel level, consumption over the window and detected refuels.
 *
 * Providers report the tank as a percentage, so consumption is expressed in
 * percentage points of tank; a jump of REFUEL_JUMP points or more between two
 * consecutive readings is treated as a refuel.
 */
final class AssetFuelTool implements CopilotTool
{
    private const REFUEL_JUMP = 10.0;

    private const LOW_FUEL_PERCENT = 20.0;

    /**
     * A sudden drop this large between two readings is flagged for review
     * (possible theft or sensor error).
     */
    private const SUDDEN_DROP = 15.0;

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('assets.view')) {
            return CopilotToolResult::denied('asset_fuel', 'Combustible', 'activos');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $label = CopilotPresenter::assetLabel($asset);

        $series = AssetTelemetrySnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('telemetry_type', TelemetryType::Fuel)
            ->whereBetween('recorded_at', [$context->period->from, $context->period->to])
            ->orderBy('recorded_at')
            ->limit(1000)
            ->get()
            ->filter(fn (AssetTelemetrySnapshot $s) => is_numeric($s->data_json['value'] ?? null))
            ->values();

        $latest = AssetTelemetrySnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('telemetry_type', TelemetryType::Fuel)
            ->orderByDesc('recorded_at')
            ->first();

        if ($latest === null) {
            return new CopilotToolResult(
                tool: 'asset_fuel',
                label: 'Combustible',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => "{$label} no reporta nivel de combustible. Verifica que el proveedor tenga habilitado el sensor de tanque."]],
                facts: ['asset' => $label, 'fuel' => null],
                highlights: ["{$label} no reporta nivel de combustible."],
            );
        }

        $consumed = 0.0;
        $refuels = [];
        $drops = [];

        for ($i = 1; $i < $series->count(); $i++) {
            $previous = (float) $series[$i - 1]->data_json['value'];
            $current = (float) $series[$i]->data_json['value'];
            $delta = $current - $previous;

            if ($delta >= self::REFUEL_JUMP) {
                $refuels[] = [
                    'at' => $series[$i]->recorded_at->toIso8601String(),
                    'from' => round($previous, 1),
                    'to' => round($current, 1),
                ];
            } elseif ($delta < 0) {
                $consumed += -$delta;

                if (-$delta >= self::SUDDEN_DROP) {
                    $drops[] = [
                        'at' => $series[$i]->recorded_at->toIso8601String(),
                        'from' => round($previous, 1),
                        'to' => round($current, 1),
                    ];
                }
            }
        }

        $current = round((float) ($latest->data_json['value'] ?? 0), 1);
        $rawUnit = (string) ($latest->data_json['unit'] ?? '%');
        $unit = in_array(strtolower($rawUnit), ['%', 'percent', 'pct'], true) ? '%' : $rawUnit;

        $block = [
            'type' => 'fuel',
            'assetId' => (int) $asset->id,
            'assetLabel' => $label,
            'period' => $context->period->label,
            'current' => $current,
            'unit' => $unit,
            'recordedAt' => $latest->recorded_at->toIso8601String(),
            'low' => $current <= self::LOW_FUEL_PERCENT,
            'consumed' => round($consumed, 1),
            'refuels' => $refuels,
            'suddenDrops' => $drops,
            'series' => $series->take(-120)->values()->map(fn (AssetTelemetrySnapshot $s) => [
                't' => $s->recorded_at->toIso8601String(),
                'v' => round((float) $s->data_json['value'], 1),
            ])->all(),
        ];

        $highlights = ["Tanque de {$label} al {$current}{$unit} (".CopilotPresenter::describeAge($block['recordedAt']).').'];

        if ($series->count() >= 2) {
            $highlights[] = 'Consumo en '.$context->period->label.': '.round($consumed, 1).' puntos de tanque, con '.count($refuels).' recarga(s) detectada(s).';
        }

        if ($block['low']) {
            $highlights[] = 'Nivel bajo: conviene programar carga.';
        }

        if ($drops !== []) {
            $highlights[] = count($drops).' caída(s) brusca(s) de nivel para revisar (posible robo o falla de sensor).';
        }

        return new CopilotToolResult(
            tool: 'asset_fuel',
            label: 'Combustible',
            blocks: [$block],
            facts: [
                'asset' => $label,
                'period' => $context->period->label,
                'current_level' => "{$current}{$unit}",
                'consumed_points' => round($consumed, 1),
                'refuels' => count($refuels),
                'sudden_drops' => count($drops),
                'low_fuel' => $block['low'],
            ],
            highlights: $highlights,
        );
    }
}
