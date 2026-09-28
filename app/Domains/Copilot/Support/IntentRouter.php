<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Copilot\Enums\CopilotIntent;

/**
 * Maps a Spanish question to an intent. Ordered from most to least specific:
 * "video del pánico de T555" is a media question, not a panic KPI one.
 */
final class IntentRouter
{
    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        'asset_media' => '/\b(video|videos|foto|fotos|imagen|imagenes|media|camara|camaras|snapshot|evidencia|grabacion|clip)\b/u',
        'panic_kpis' => '/\b(panico|panicos|sos|boton de emergencia|botones)\b/u',
        'fuel_report' => '/\b(combustible|diesel|gasolina|tanque|litros|rendimiento|recarga|recargas|carga de combustible)\b/u',
        'engine_stats' => '/\b(motor|ignicion|odometro|kilometraje|kilometros recorridos|bateria|temperatura|encendido|apagado|velocidad maxima|estadisticas)\b/u',
        'asset_location' => '/\b(donde|ubicacion|ubicar|localiza|localizacion|posicion|ubicado|mapa|coordenadas)\b/u',
        'asset_report' => '/\b(reporte|informe|todo|completo|ficha|status|estado de la unidad|resumen de la unidad)\b/u',
        'open_incidents' => '/\b(incidente|incidentes|critico|criticos|sla|abiertos|pendientes|alertas)\b/u',
        'driver_ranking' => '/\b(conductor|conductores|chofer|choferes|operador|operadores|ranking|riesgo|fatiga)\b/u',
        'fleet_overview' => '/\b(flota|unidades|en ruta|cuantas|cuantos|resumen|detenidas|sin senal)\b/u',
    ];

    /**
     * Words or economic-number-like tokens ("T555", "R-12") that show the
     * question is about one specific unit, even if we couldn't match it.
     */
    private const UNIT_REFERENCE = '/\b(unidad|camion|tracto|tractor|remolque|caja|economico|pipa)\b|\b[a-z]{1,3}-?\d{2,5}\b/u';

    public function mentionsUnit(string $prompt): bool
    {
        return (bool) preg_match(self::UNIT_REFERENCE, CopilotText::normalize($prompt));
    }

    /**
     * The unit-like token the operator typed (e.g. "t555"), if any.
     */
    public function unitToken(string $prompt): ?string
    {
        return preg_match('/\b([a-z]{1,3}-?\d{2,5})\b/u', CopilotText::normalize($prompt), $match)
            ? mb_strtoupper($match[1])
            : null;
    }

    public function route(string $prompt, bool $hasAsset): CopilotIntent
    {
        $normalized = CopilotText::normalize($prompt);
        $aboutOneUnit = $hasAsset || $this->mentionsUnit($prompt);

        foreach (self::PATTERNS as $intent => $pattern) {
            if (! preg_match($pattern, $normalized)) {
                continue;
            }

            $resolved = CopilotIntent::from($intent);

            // "¿dónde están mis remolques?" has no single asset: that's a
            // fleet question, not an asset one.
            if ($resolved->requiresAsset() && ! $hasAsset && $resolved === CopilotIntent::AssetLocation) {
                return CopilotIntent::FleetOverview;
            }

            // "resumen del turno" / "reporte de incidentes" without a unit.
            if ($resolved === CopilotIntent::AssetReport && ! $aboutOneUnit) {
                continue;
            }

            return $resolved;
        }

        return $aboutOneUnit ? CopilotIntent::AssetReport : CopilotIntent::General;
    }
}
