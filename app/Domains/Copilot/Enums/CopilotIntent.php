<?php

namespace App\Domains\Copilot\Enums;

/**
 * What the operator is asking for. Each intent maps to a fixed set of data
 * tools, so the answer is always grounded on the tenant's own records — the
 * language model (when configured) only narrates what the tools returned.
 */
enum CopilotIntent: string
{
    case AssetReport = 'asset_report';
    case AssetLocation = 'asset_location';
    case AssetMedia = 'asset_media';
    case EngineStats = 'engine_stats';
    case FuelReport = 'fuel_report';
    case PanicKpis = 'panic_kpis';
    case OpenIncidents = 'open_incidents';
    case DriverRanking = 'driver_ranking';
    case FleetOverview = 'fleet_overview';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::AssetReport => 'Reporte completo de unidad',
            self::AssetLocation => 'Ubicación de unidad',
            self::AssetMedia => 'Última media de unidad',
            self::EngineStats => 'Estadísticas de motor',
            self::FuelReport => 'Reporte de combustible',
            self::PanicKpis => 'KPIs de botón de pánico',
            self::OpenIncidents => 'Incidentes abiertos',
            self::DriverRanking => 'Ranking de conductores',
            self::FleetOverview => 'Estado de la flota',
            self::General => 'Consulta general',
        };
    }

    /**
     * Intents that only make sense about one specific asset.
     */
    public function requiresAsset(): bool
    {
        return in_array($this, [
            self::AssetReport,
            self::AssetLocation,
            self::AssetMedia,
            self::EngineStats,
            self::FuelReport,
        ], true);
    }
}
