<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\IncidentRows;
use App\Domains\Incidents\Models\Incident;

/**
 * Live state of the incident inbox: open by priority and SLA exposure.
 */
final class OpenIncidentsTool implements CopilotTool
{
    private const LIST_LIMIT = 6;

    /**
     * @var array<string, int>
     */
    private const PRIORITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('incidents.view')) {
            return CopilotToolResult::denied('open_incidents', 'Bandeja en vivo', 'incidentes');
        }

        $query = Incident::query()
            ->where('team_id', $context->teamId)
            ->open()
            ->with(['status', 'priority', 'asset', 'driver', 'currentAssignment']);

        if ($context->asset !== null) {
            $query->where('asset_id', $context->asset->id);
        }

        $open = $query->limit(2000)->get();

        $byPriority = $open->countBy(fn (Incident $i) => $i->priority?->code ?? 'low');
        $breached = $open->filter(fn (Incident $i) => $i->sla_due_at !== null && $i->sla_due_at->isPast())->count();
        $dueSoon = $open->filter(fn (Incident $i) => $i->sla_due_at !== null && $i->sla_due_at->isFuture() && $i->sla_due_at->lte(now()->addMinutes(15)))->count();
        $unassigned = $open->filter(fn (Incident $i) => $i->currentAssignment === null)->count();

        $top = $open
            ->sortBy(fn (Incident $i) => [
                self::PRIORITY_ORDER[$i->priority?->code ?? 'low'] ?? 9,
                $i->sla_due_at?->getTimestamp() ?? PHP_INT_MAX,
            ])
            ->take(self::LIST_LIMIT)
            ->values();

        $blocks = [[
            'type' => 'kpis',
            'items' => [
                ['label' => 'Abiertos', 'value' => $open->count()],
                ['label' => 'Críticos', 'value' => $byPriority->get('critical', 0), 'tone' => $byPriority->get('critical', 0) > 0 ? 'critical' : null],
                ['label' => 'Altos', 'value' => $byPriority->get('high', 0), 'tone' => $byPriority->get('high', 0) > 0 ? 'high' : null],
                ['label' => 'SLA < 15 min', 'value' => $dueSoon, 'tone' => $dueSoon > 0 ? 'high' : null],
                ['label' => 'SLA vencido', 'value' => $breached, 'tone' => $breached > 0 ? 'critical' : null],
                ['label' => 'Sin asignar', 'value' => $unassigned],
            ],
        ]];

        if ($top->isNotEmpty()) {
            $blocks[] = [
                'type' => 'incidents',
                'title' => 'Prioridad de atención',
                'items' => $top->map(fn (Incident $i) => IncidentRows::row($i, $context->teamSlug))->all(),
            ];
        }

        return new CopilotToolResult(
            tool: 'open_incidents',
            label: 'Bandeja en vivo',
            blocks: $blocks,
            sources: array_values($top->map(fn (Incident $i) => IncidentRows::source($i, $context->teamSlug))->all()),
            facts: [
                'open' => $open->count(),
                'by_priority' => $byPriority->all(),
                'sla_breached' => $breached,
                'sla_due_15_min' => $dueSoon,
                'unassigned' => $unassigned,
                'top' => $top->map(fn (Incident $i) => ['title' => $i->title, 'priority' => $i->priority?->code, 'asset' => $i->asset?->code])->all(),
            ],
            highlights: [
                "Hay {$open->count()} incidente(s) abierto(s): ".$byPriority->get('critical', 0).' crítico(s) y '.$byPriority->get('high', 0).' alto(s).',
                $breached > 0 ? "{$breached} ya vencieron su SLA." : 'Ninguno tiene el SLA vencido.',
            ],
        );
    }
}
