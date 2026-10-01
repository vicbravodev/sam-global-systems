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

        $rows = [];

        foreach ($drivers as $driver) {
            // El join exige perfil; sólo una carrera (perfil borrado entre el
            // join y el eager load) lo dejaría en null: esa fila se omite.
            $profile = $driver->riskProfile;

            if ($profile === null) {
                continue;
            }

            $rows[] = [
                'id' => (int) $driver->id,
                'name' => (string) $driver->full_name,
                'employeeCode' => $driver->employee_code,
                'score' => round((float) $profile->risk_score),
                'level' => $profile->risk_level?->value,
                'levelLabel' => self::LEVEL_LABELS[$profile->risk_level?->value ?? 'low'],
                'incidents' => (int) $profile->incidents_count,
                'harsh' => (int) $profile->harsh_events_count,
                'fatigue' => (int) $profile->fatigue_flags_count,
                'href' => CopilotPresenter::driverHref($context->teamSlug, (int) $driver->id),
            ];
        }

        if ($rows === []) {
            return new CopilotToolResult(
                tool: 'driver_ranking',
                label: 'Conductores',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => 'Todavía no hay perfiles de riesgo calculados para los conductores de este tenant.']],
                facts: ['drivers' => []],
                highlights: ['Aún no hay perfiles de riesgo de conductores.'],
            );
        }

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
