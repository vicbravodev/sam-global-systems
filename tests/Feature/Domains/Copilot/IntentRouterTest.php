<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Copilot\Data\CopilotPeriod;
use App\Domains\Copilot\Enums\CopilotIntent;
use App\Domains\Copilot\Support\CopilotText;
use App\Domains\Copilot\Support\IntentRouter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntentRouterTest extends TestCase
{
    #[DataProvider('questions')]
    public function test_it_routes_how_managers_actually_ask(string $question, bool $hasAsset, CopilotIntent $expected): void
    {
        $this->assertSame($expected, (new IntentRouter)->route($question, $hasAsset));
    }

    /**
     * @return array<string, array{string, bool, CopilotIntent}>
     */
    public static function questions(): array
    {
        return [
            'reporte de unidad' => ['Dame el reporte de la unidad T555', true, CopilotIntent::AssetReport],
            'reporte de unidad inexistente' => ['Dame el reporte de la unidad T999', false, CopilotIntent::AssetReport],
            'solo el número' => ['T555', true, CopilotIntent::AssetReport],
            'dónde está' => ['¿Dónde está el camión T555?', true, CopilotIntent::AssetLocation],
            'dónde están mis remolques' => ['¿Dónde están mis remolques?', false, CopilotIntent::FleetOverview],
            'video' => ['Muéstrame el último video de la T555', true, CopilotIntent::AssetMedia],
            'pánico con video' => ['Video del botón de pánico de T555', true, CopilotIntent::AssetMedia],
            'kpis de pánico' => ['KPIs de los últimos botones de pánico', false, CopilotIntent::PanicKpis],
            'motor' => ['Estadísticas de motor de T555', true, CopilotIntent::EngineStats],
            'kilometraje' => ['¿Cuánto kilometraje hizo la 555 esta semana?', true, CopilotIntent::EngineStats],
            'combustible' => ['¿Cuánto diésel le queda?', true, CopilotIntent::FuelReport],
            'críticos' => ['¿Cuántos incidentes críticos hay abiertos?', false, CopilotIntent::OpenIncidents],
            'conductores' => ['Ranking de choferes por riesgo', false, CopilotIntent::DriverRanking],
            'flota' => ['¿Cómo está la flota?', false, CopilotIntent::FleetOverview],
            'reporte sin unidad' => ['Reporte de incidentes de hoy', false, CopilotIntent::OpenIncidents],
            'saludo' => ['Hola', false, CopilotIntent::General],
        ];
    }

    public function test_periods_are_read_from_the_question(): void
    {
        $now = CarbonImmutable::parse('2026-09-28 12:00:00');

        $this->assertSame('hoy', CopilotPeriod::fromPrompt(CopilotText::normalize('pánicos de hoy'), $now)->label);
        $this->assertSame('últimos 30 días', CopilotPeriod::fromPrompt(CopilotText::normalize('combustible del mes'), $now)->label);
        $this->assertSame(14, CopilotPeriod::fromPrompt(CopilotText::normalize('últimos 14 días'), $now)->days);
        $this->assertSame('últimos 7 días', CopilotPeriod::fromPrompt(CopilotText::normalize('motor de T555'), $now)->label);
    }
}
