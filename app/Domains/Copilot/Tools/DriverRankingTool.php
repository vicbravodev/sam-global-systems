<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Drivers\Models\Driver;

/**
 * Drivers ranked by their computed risk profile.
 */
final class DriverRankingTool implements CopilotTool
{
    private const LIMIT = 8;

    /**
     * @var array<string, string>
     */
    private const LEVEL_LABELS = ['low' => 'Bajo', 'medium' => 'Medio', 'high' => 'Alto', 'critical' => 'Crítico'];

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('drivers.view')) {
            return CopilotToolResult::denied('driver_ranking', 'Conductores', 'conductores');
        }

        $drivers = Driver::query()
            ->where('drivers.team_id', $context->teamId)
            ->join('driver_risk_profiles', 'driver_risk_profiles.driver_id', '=', 'drivers.id')
            ->orderByDesc('driver_risk_profiles.risk_score')
            ->limit(self::LIMIT)
            ->select('drivers.*')
            ->with('riskProfile')
            ->get();

        if ($drivers->isEmpty()) {
            return new CopilotToolResult(
                tool: 'driver_ranking',
                label: 'Conductores',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => 'Todavía no hay perfiles de riesgo calculados para los conductores de este tenant.']],
                facts: ['drivers' => []],
                highlights: ['Aún no hay perfiles de riesgo de conductores.'],
            );
        }

        $rows = $drivers->map(fn (Driver $driver) => [
            'id' => (int) $driver->id,
            'name' => (string) $driver->full_name,
            'employeeCode' => $driver->employee_code,
            'score' => round((float) $driver->riskProfile->risk_score),
            'level' => $driver->riskProfile->risk_level?->value,
            'levelLabel' => self::LEVEL_LABELS[$driver->riskProfile->risk_level?->value ?? 'low'],
            'incidents' => (int) $driver->riskProfile->incidents_count,
            'harsh' => (int) $driver->riskProfile->harsh_events_count,
            'fatigue' => (int) $driver->riskProfile->fatigue_flags_count,
            'href' => CopilotPresenter::driverHref($context->teamSlug, (int) $driver->id),
        ])->all();

        $first = $rows[0];

        return new CopilotToolResult(
            tool: 'driver_ranking',
            label: 'Perfiles de riesgo',
            blocks: [['type' => 'drivers', 'items' => $rows]],
            sources: array_map(fn (array $r) => ['kind' => 'driver', 'id' => $r['id'], 'label' => $r['name'], 'href' => $r['href']], array_slice($rows, 0, 5)),
            facts: ['ranking' => array_map(fn (array $r) => [
                'name' => $r['name'],
                'score' => $r['score'],
                'incidents' => $r['incidents'],
                'harsh' => $r['harsh'],
                'fatigue' => $r['fatigue'],
            ], $rows)],
            highlights: ["{$first['name']} encabeza el ranking de riesgo con {$first['score']} puntos ({$first['harsh']} eventos bruscos, {$first['fatigue']} alertas de fatiga)."],
        );
    }
}
