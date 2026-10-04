<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Copilot\Support\IncidentRows;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use Carbon\CarbonImmutable;

/**
 * Operational history of a unit: events per day and its incidents.
 */
final class AssetActivityTool implements CopilotTool
{
    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('incidents.view')) {
            return CopilotToolResult::denied('asset_activity', 'Eventos e incidentes', 'incidentes');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $label = CopilotPresenter::assetLabel($asset);
        $period = $context->period;

        $events = NormalizedEvent::query()
            ->countable()
            ->where('team_id', $context->teamId)
            ->where('asset_id', $asset->id)
            ->whereBetween('occurred_at', [$period->from, $period->to])
            ->with('eventType')
            ->get(['id', 'event_type_id', 'occurred_at']);

        $days = max(1, min(14, $period->days));
        $bars = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::parse($period->to)->subDays($i)->startOfDay();
            $bars[] = [
                'label' => $day->settings(['locale' => 'es'])->isoFormat('dd D'),
                'value' => $events->filter(fn (NormalizedEvent $e) => $e->occurred_at->isSameDay($day))->count(),
            ];
        }

        $topTypes = $events
            ->groupBy(fn (NormalizedEvent $e) => $e->eventType?->name ?? 'Sin tipo')
            ->map->count()
            ->sortDesc()
            ->take(4);

        $incidents = Incident::query()
            ->where('team_id', $context->teamId)
            ->where('asset_id', $asset->id)
            ->with(['status', 'priority', 'asset', 'driver', 'currentAssignment'])
            ->orderByDesc('opened_at')
            ->limit(5)
            ->get();

        $openCount = Incident::query()
            ->where('team_id', $context->teamId)
            ->where('asset_id', $asset->id)
            ->open()
            ->count();

        $blocks = [[
            'type' => 'bars',
            'title' => "Eventos por día · {$period->label}",
            'total' => $events->count(),
            'items' => $bars,
            'legend' => $topTypes->map(fn (int $count, string $name) => ['label' => $name, 'value' => $count])->values()->all(),
        ]];

        if ($incidents->isNotEmpty()) {
            $blocks[] = [
                'type' => 'incidents',
                'title' => "Incidentes recientes de {$asset->code}",
                'items' => $incidents->map(fn (Incident $i) => IncidentRows::row($i, $context->teamSlug))->all(),
            ];
        }

        $highlights = ["{$label} generó {$events->count()} evento(s) en {$period->label}"
            .($topTypes->isNotEmpty() ? ', sobre todo '.mb_strtolower((string) $topTypes->keys()->first()) : '')
            .'.'];
        $highlights[] = $openCount > 0
            ? "Tiene {$openCount} incidente(s) abierto(s)."
            : 'No tiene incidentes abiertos.';

        return new CopilotToolResult(
            tool: 'asset_activity',
            label: 'Eventos e incidentes',
            blocks: $blocks,
            sources: array_values($incidents->map(fn (Incident $i) => IncidentRows::source($i, $context->teamSlug))->all()),
            facts: [
                'asset' => $label,
                'period' => $period->label,
                'events' => $events->count(),
                'top_event_types' => $topTypes->all(),
                'open_incidents' => $openCount,
                'recent_incidents' => $incidents->map(fn (Incident $i) => [
                    'title' => $i->title,
                    'priority' => $i->priority?->code,
                    'opened_at' => $i->opened_at?->toIso8601String(),
                ])->all(),
            ],
            highlights: $highlights,
        );
    }
}
