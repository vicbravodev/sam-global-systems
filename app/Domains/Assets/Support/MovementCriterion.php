<?php

namespace App\Domains\Assets\Support;

use App\Domains\Assets\Models\Asset;

/**
 * El ÚNICO criterio de "en movimiento" de SAM (decisión 2026-09-28: lo define
 * SAM, no cada detector). Una unidad se mueve cuando su lectura va a
 * `telematics.moving_speed_kph` o más (5 km/h) Y su estado de movimiento —el
 * que mantiene la ingesta de telemetría— no la tiene detenida: una unidad
 * parada sólo vuelve a moverse al salir más de `telematics.stop_exit_radius_m`
 * (50 m) del punto donde se detuvo. Así los picos fantasma de GPS de un
 * tracto estacionado (6 km/h en el mismo punto) no cuentan en ningún detector:
 * paradas, fuera de horario ni "dejó de reportar".
 */
final class MovementCriterion
{
    public static function speedThresholdKph(): float
    {
        return (float) config('telematics.moving_speed_kph', 5.0);
    }

    public static function isMovingSpeed(?float $speedKph): bool
    {
        return $speedKph !== null && $speedKph >= self::speedThresholdKph();
    }

    /**
     * Estado de movimiento de la unidad (histéresis de lugar incluida).
     */
    public static function assetIsMoving(Asset $asset): bool
    {
        return $asset->stopped_since === null && $asset->last_moving_at !== null;
    }

    /**
     * Velocidad Y estado: lo que cualquier detector debe exigir.
     */
    public static function isMoving(Asset $asset, ?float $speedKph): bool
    {
        return self::isMovingSpeed($speedKph) && self::assetIsMoving($asset);
    }
}
