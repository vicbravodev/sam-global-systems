<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Assets\Models\AssetTelemetrySnapshot;

final class FuelConsumption
{
    public const REFUEL_JUMP = 10.0;

    public const SUDDEN_DROP = 15.0;

    /**
     * Percentage points of tank consumed in an ordered series: drops add up,
     * a jump of REFUEL_JUMP or more is a refuel, a drop of SUDDEN_DROP or more
     * is flagged (possible theft or sensor glitch).
     *
     * @param  iterable<AssetTelemetrySnapshot>  $series  ordered by recorded_at
     * @return array{consumed: float, refuels: list<array{at: string, from: float, to: float}>, drops: list<array{at: string, from: float, to: float}>}
     */
    public static function fromSeries(iterable $series): array
    {
        $points = collect($series)->values();
        $consumed = 0.0;
        $refuels = [];
        $drops = [];

        for ($i = 1; $i < $points->count(); $i++) {
            $previous = (float) $points[$i - 1]->data_json['value'];
            $current = (float) $points[$i]->data_json['value'];
            $delta = $current - $previous;
            $mark = ['at' => $points[$i]->recorded_at->toIso8601String(), 'from' => round($previous, 1), 'to' => round($current, 1)];

            if ($delta >= self::REFUEL_JUMP) {
                $refuels[] = $mark;
            } elseif ($delta < 0) {
                $consumed += -$delta;

                if (-$delta >= self::SUDDEN_DROP) {
                    $drops[] = $mark;
                }
            }
        }

        return ['consumed' => round($consumed, 1), 'refuels' => $refuels, 'drops' => $drops];
    }
}
