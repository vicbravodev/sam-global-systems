<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Copilot\Support\IdleTimeCalculator;

/**
 * Engine and vehicle statistics: ignition, odometer, distance covered in the
 * window, speeds, battery and temperature.
 */
final class AssetEngineTool implements CopilotTool
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'ignition' => 'Motor',
        'odometer' => 'Odómetro',
        'speed' => 'Velocidad',
        'battery' => 'Batería',
        'temperature' => 'Temperatura',
        'camera_status' => 'Cámara',
    ];

    /**
     * @var array<string, string>
     */
    private const IGNITION_LABELS = [
        'on' => 'Encendido',
        'running' => 'Encendido',
        'off' => 'Apagado',
        'idle' => 'Ralentí',
    ];

    private const SERIES_LIMIT = 60;

    public function __construct(private readonly IdleTimeCalculator $idle) {}

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('assets.view')) {
            return CopilotToolResult::denied('asset_engine', 'Telemetría de motor', 'activos');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $readings = [];

        foreach (array_keys(self::LABELS) as $type) {
            $snapshot = AssetTelemetrySnapshot::query()
                ->where('asset_id', $asset->id)
                ->where('telemetry_type', $type)
                ->orderByDesc('recorded_at')
                ->first();

            if ($snapshot === null) {
                continue;
            }

            $value = $snapshot->data_json['value'] ?? null;

            if ($type === TelemetryType::Ignition->value && is_string($value)) {
                $value = self::IGNITION_LABELS[mb_strtolower($value)] ?? $value;
            }

            $readings[] = [
                'key' => $type,
                'label' => self::LABELS[$type],
                'value' => is_numeric($value) ? round((float) $value, 1) : $value,
                'unit' => $snapshot->data_json['unit'] ?? null,
                'recordedAt' => $snapshot->recorded_at->toIso8601String(),
            ];
        }

        $odometerSeries = AssetTelemetrySnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('telemetry_type', TelemetryType::Odometer)
            ->whereBetween('recorded_at', [$context->period->from, $context->period->to])
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (AssetTelemetrySnapshot $s) => (float) ($s->data_json['value'] ?? 0))
            ->filter(fn (float $v) => $v > 0);

        $distance = $odometerSeries->count() >= 2 ? round($odometerSeries->max() - $odometerSeries->min(), 1) : null;

        $positions = AssetLocationSnapshot::query()
            ->where('asset_id', $asset->id)
            ->whereBetween('recorded_at', [$context->period->from, $context->period->to])
            ->orderByDesc('recorded_at')
            ->limit(500)
            ->get(['speed', 'recorded_at']);

        $moving = $positions->filter(fn (AssetLocationSnapshot $s) => (float) $s->speed > CopilotPresenter::MOVING_SPEED_KPH);
        $maxSpeed = $positions->max(fn (AssetLocationSnapshot $s) => (float) $s->speed);
        $avgSpeed = $moving->isNotEmpty() ? round((float) $moving->avg(fn (AssetLocationSnapshot $s) => (float) $s->speed), 1) : null;
        $overSpeed = $positions->filter(fn (AssetLocationSnapshot $s) => (float) $s->speed > 100)->count();

        $idle = $this->idle->forAsset($asset, $context->period->from, $context->period->to);

        $label = CopilotPresenter::assetLabel($asset);
        $stats = [
            ['label' => 'Recorrido', 'value' => $distance !== null ? number_format($distance, 1, '.', ',') : '—', 'unit' => $distance !== null ? 'km' : null, 'hint' => $context->period->label],
            ['label' => 'Vel. máxima', 'value' => $maxSpeed !== null ? round((float) $maxSpeed) : '—', 'unit' => $maxSpeed !== null ? 'km/h' : null, 'tone' => $maxSpeed !== null && $maxSpeed > 100 ? 'high' : null],
            ['label' => 'Vel. promedio', 'value' => $avgSpeed ?? '—', 'unit' => $avgSpeed !== null ? 'km/h' : null, 'hint' => 'en movimiento'],
            ['label' => 'Lecturas > 100 km/h', 'value' => $overSpeed, 'tone' => $overSpeed > 0 ? 'high' : null],
        ];

        $block = [
            'type' => 'telemetry',
            'title' => "Motor y telemetría · {$label}",
            'period' => $context->period->label,
            'readings' => $readings,
            'stats' => $stats,
            'series' => [
                'label' => 'Velocidad (km/h)',
                'points' => $positions->take(self::SERIES_LIMIT)->reverse()->values()->map(fn (AssetLocationSnapshot $s) => [
                    't' => $s->recorded_at->toIso8601String(),
                    'v' => round((float) $s->speed, 1),
                ])->all(),
            ],
        ];

        $highlights = [];
        $ignition = collect($readings)->firstWhere('key', 'ignition');

        if ($ignition) {
            $highlights[] = "Motor {$this->lower($ignition['value'])} (".CopilotPresenter::describeAge($ignition['recordedAt']).').';
        }

        if ($distance !== null) {
            $highlights[] = "Recorrió {$distance} km en {$context->period->label}.";
        }

        if ($maxSpeed !== null) {
            $highlights[] = 'Velocidad máxima registrada: '.round((float) $maxSpeed).' km/h'.($overSpeed > 0 ? " ({$overSpeed} lecturas sobre 100 km/h)." : '.');
        }

        if ($idle->hours > 0) {
            $highlights[] = "Ralentí: {$idle->hours} h en {$context->period->label}.";
        }

        if ($readings === [] && $positions->isEmpty()) {
            $highlights[] = "{$label} no tiene telemetría de motor en {$context->period->label}.";
        }

        $facts = [
            'asset' => $label,
            'period' => $context->period->label,
            'readings' => array_map(fn (array $r) => [$r['label'] => trim($r['value'].' '.($r['unit'] ?? ''))], $readings),
            'distance_km' => $distance,
            'max_speed_kph' => $maxSpeed,
            'avg_moving_speed_kph' => $avgSpeed,
            'readings_over_100_kph' => $overSpeed,
        ];

        if ($idle->hours > 0) {
            $facts['idle_hours'] = $idle->hours;
            $facts['idle_source'] = $idle->source;
        }

        return new CopilotToolResult(
            tool: 'asset_engine',
            label: 'Telemetría de motor',
            blocks: [$block],
            facts: $facts,
            highlights: $highlights,
        );
    }

    private function lower(mixed $value): string
    {
        return mb_strtolower((string) $value);
    }
}
