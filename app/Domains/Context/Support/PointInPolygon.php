<?php

namespace App\Domains\Context\Support;

class PointInPolygon
{
    /**
     * Ray-casting algorithm for point-in-polygon containment.
     *
     * @param  array<int, array{0: float|int, 1: float|int}>  $polygon  Ring of [lng, lat] coordinates (GeoJSON format; whole-degree vertices decode as int). First and last vertices may coincide.
     */
    public static function contains(array $polygon, float $lat, float $lng): bool
    {
        $vertexCount = count($polygon);

        if ($vertexCount < 3) {
            return false;
        }

        $inside = false;
        $j = $vertexCount - 1;

        for ($i = 0; $i < $vertexCount; $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            // Arista horizontal: se divide por un epsilon en vez de por cero.
            $dy = (float) ($yj - $yi);

            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / ($dy === 0.0 ? 1e-12 : $dy) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }

            $j = $i;
        }

        return $inside;
    }
}
