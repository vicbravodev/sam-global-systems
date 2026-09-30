<?php

namespace App\Domains\Copilot\Tools\Sdk;

use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Tools\AssetActivityTool;
use App\Domains\Copilot\Tools\AssetEngineTool;
use App\Domains\Copilot\Tools\AssetFuelTool;
use App\Domains\Copilot\Tools\AssetLocationTool;
use App\Domains\Copilot\Tools\AssetMediaTool;
use App\Domains\Copilot\Tools\AssetSummaryTool;
use App\Domains\Copilot\Tools\AssetTimelineTool;
use App\Domains\Copilot\Tools\CopilotTool;
use App\Domains\Copilot\Tools\DriverRankingTool;
use App\Domains\Copilot\Tools\FindAssetsTool;
use App\Domains\Copilot\Tools\FleetOverviewTool;
use App\Domains\Copilot\Tools\OpenIncidentsTool;
use App\Domains\Copilot\Tools\PanicKpisTool;
use App\Domains\Copilot\Tools\RankAssetsTool;
use App\Domains\Copilot\Tools\SearchEventsTool;

/**
 * Registry of the data tools the Copilot agent can call: the name and
 * description the model sees, the domain tool that answers, the module
 * permission that gates it and the intent it stands for.
 *
 * `permission` mirrors the check each domain tool does in its `run()`; the
 * toolbox uses it to never offer the model a tool the user cannot read.
 */
final readonly class CopilotToolDefinition
{
    /**
     * @param  class-string<CopilotTool>  $toolClass
     * @param  class-string<SdkCopilotTool>|null  $sdkClass  SDK adapter; defaults to DelegatingCopilotTool.
     * @param  string|null  $label  Human name used in errors; defaults to the intent's label.
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $toolClass,
        public ?string $permission,
        public CopilotIntent $intent,
        public bool $needsAsset,
        public ?string $sdkClass = null,
        public ?string $label = null,
    ) {}

    /**
     * @return array<string, self>
     */
    public static function all(): array
    {
        return collect([
            new self('asset_summary', 'Ficha de una unidad: estado, conductor asignado, última señal. Requiere asset_code.', AssetSummaryTool::class, 'assets.view', CopilotIntent::AssetReport, true, label: 'Resumen de unidad'),
            new self('asset_location', 'Ubicación actual, velocidad y recorrido reciente de una unidad. Requiere asset_code.', AssetLocationTool::class, 'assets.view', CopilotIntent::AssetLocation, true),
            new self('asset_engine', 'Motor de una unidad: ignición, odómetro, km recorridos, batería, temperatura y horas de ralentí en el periodo. Requiere asset_code.', AssetEngineTool::class, 'assets.view', CopilotIntent::EngineStats, true),
            new self('asset_fuel', 'Combustible de una unidad (% de tanque): nivel actual, consumo, recargas y caídas bruscas en el periodo. Requiere asset_code.', AssetFuelTool::class, 'assets.view', CopilotIntent::FuelReport, true),
            new self('asset_media', 'Últimas fotos y videos de cámara de una unidad (p. ej. los de un botón de pánico), con el incidente al que pertenecen y lo que la IA de visión vio en cada archivo. Requiere asset_code.', AssetMediaTool::class, 'context.view', CopilotIntent::AssetMedia, true),
            new self('asset_activity', 'Eventos por día e incidentes de una unidad en el periodo. Requiere asset_code.', AssetActivityTool::class, 'incidents.view', CopilotIntent::AssetReport, true, label: 'Eventos e incidentes de unidad'),
            new self('panic_kpis', 'KPIs de botón de pánico en el periodo: cantidad, tiempos de atención, unidades. asset_code opcional.', PanicKpisTool::class, 'incidents.view', CopilotIntent::PanicKpis, false),
            new self('open_incidents', 'Incidentes abiertos ahora, por severidad y SLA. asset_code opcional.', OpenIncidentsTool::class, 'incidents.view', CopilotIntent::OpenIncidents, false),
            new self('driver_ranking', 'Ranking de conductores por riesgo y fatiga.', DriverRankingTool::class, 'drivers.view', CopilotIntent::DriverRanking, false),
            new self('fleet_overview', 'Estado de toda la flota o una categoría: en ruta, detenidas, sin señal, en alerta, con mapa.', FleetOverviewTool::class, 'assets.view', CopilotIntent::FleetOverview, false),
            new self('find_assets', 'Busca unidades por número económico o nombre cuando no estás seguro del código exacto.', FindAssetsTool::class, 'assets.view', CopilotIntent::General, false, FindAssetsSdkTool::class, 'Búsqueda de unidades'),
            new self('rank_assets', 'Compara TODAS las unidades por una métrica en un periodo y marca las atípicas. Úsala para "cuál gastó más", "top", "peores", "comparar flota".', RankAssetsTool::class, 'assets.view', CopilotIntent::AssetRanking, false, RankAssetsSdkTool::class),
            new self('search_events', 'Busca eventos de la flota en el periodo por tipo, severidad o unidad: conteos por tipo y severidad y los más recientes. asset_code opcional.', SearchEventsTool::class, 'incidents.view', CopilotIntent::EventSearch, false, SearchEventsSdkTool::class),
            new self('asset_timeline', 'Línea de tiempo de una unidad en el periodo: eventos, incidentes y tramos de ralentí de 10 min o más, en orden cronológico. Requiere asset_code.', AssetTimelineTool::class, 'incidents.view', CopilotIntent::AssetTimeline, true),
        ])->keyBy('name')->all();
    }

    public static function intentFor(string $tool): ?CopilotIntent
    {
        return self::all()[$tool]->intent ?? null;
    }

    public function displayLabel(): string
    {
        return $this->label ?? $this->intent->label();
    }
}
