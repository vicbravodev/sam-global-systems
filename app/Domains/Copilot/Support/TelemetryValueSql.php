<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Assets\Models\AssetTelemetrySnapshot;

/**
 * `data_json->value` de `asset_telemetry_snapshots` como SQL literal, con la
 * sintaxis JSON de la conexión: `->>` en PostgreSQL (producción) y
 * `json_extract` en SQLite (tests). Es exactamente lo que produce
 * `Grammar::wrap('data_json->value')`, pero como literal fijo para que nunca
 * entre texto dinámico en una expresión SQL cruda.
 */
final class TelemetryValueSql
{
    /**
     * @return literal-string
     */
    public static function value(): string
    {
        return (new AssetTelemetrySnapshot)->getConnection()->getDriverName() === 'sqlite'
            ? 'json_extract("data_json", \'$."value"\')'
            : '"data_json"->>\'value\'';
    }

    /**
     * El valor como número de punto flotante.
     *
     * @return literal-string
     */
    public static function numeric(): string
    {
        $value = self::value();

        return "cast({$value} as double precision)";
    }
}
