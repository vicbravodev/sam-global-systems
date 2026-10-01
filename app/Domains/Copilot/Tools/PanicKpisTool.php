<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Panic-button KPIs: how many, when, which units, how fast the monitoring
 * room reacted and how many turned out to be false alarms.
 */
final class PanicKpisTool implements CopilotTool
{
    private const PANIC_CODE = 'panic_button';

    private const LIST_LIMIT = 6;

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('incidents.view')) {
            return CopilotToolResult::denied('panic_kpis', 'Botón de pánico', 'incidentes');
        }

        $period = $context->period;
        // Global event-type catalog: no tenant column.
        $panicTypeIds = EventType::query()->where('code', self::PANIC_CODE)->pluck('id');

        $query = NormalizedEvent::query()
            ->where('team_id', $context->teamId)
            ->whereIn('event_type_id', $panicTypeIds)
            ->whereBetween('occurred_at', [$period->from, $period->to]);

        if ($context->asset !== null) {
            $query->where('asset_id', $context->asset->id);
        }

        /** @var Collection<int, NormalizedEvent> $events */
        $events = $query->with(['asset', 'driver'])->orderByDesc('occurred_at')->get();

        $incidents = Incident::query()
            ->where('team_id', $context->teamId)
            ->whereIn('related_event_id', $events->pluck('id'))
            ->with(['status', 'priority', 'asset', 'driver', 'currentAssignment'])
            ->get()
            ->keyBy('related_event_id');

        $acknowledged = $incidents->filter(fn (Incident $i) => $i->acknowledged_at !== null);
        $avgResponseSeconds = $acknowledged->isNotEmpty()
            ? (int) round((float) $acknowledged->avg(fn (Incident $i) => $i->opened_at->diffInSeconds($i->acknowledged_at)))
            : null;
        $falseAlarms = $incidents->filter(fn (Incident $i) => $i->status?->code === IncidentStatusCode::FalsePositive->value)->count();
        $open = $incidents->filter(fn (Incident $i) => ! $i->isTerminal())->count();
        $today = $events->filter(fn (NormalizedEvent $e) => $e->occurred_at->isToday())->count();

        $days = max(1, min(14, $period->days));
        $bars = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = CarbonImmutable::parse($period->to)->subDays($i)->startOfDay();
            $bars[] = [
                'label' => $day->settings(['locale' => 'es'])->isoFormat('dd D'),
                'value' => $events->filter(fn (NormalizedEvent $e) => $e->occurred_at->isSameDay($day))->count(),
            ];
        }

        $topAssets = $events
            ->filter(fn (NormalizedEvent $e) => $e->asset !== null)
            ->groupBy('asset_id')
            ->map(function (Collection $group): array {
                // Filtrado arriba: cada grupo tiene al menos un evento con activo.
                $asset = $group->first()?->asset;

                return [
                    'label' => $asset?->code ?? $asset?->name,
                    'value' => $group->count(),
                ];
            })
            ->sortByDesc('value')
            ->take(4)
            ->values();

        $kpis = [
            ['label' => 'Pánicos', 'value' => $events->count(), 'tone' => $events->count() > 0 ? 'critical' : null, 'hint' => $period->label],
            ['label' => 'Hoy', 'value' => $today],
            ['label' => 'Abiertos', 'value' => $open, 'tone' => $open > 0 ? 'critical' : null],
            ['label' => 'Resp. media', 'value' => $avgResponseSeconds !== null ? $this->formatDuration($avgResponseSeconds) : '—', 'hint' => 'apertura → acuse'],
            ['label' => 'Falsas alarmas', 'value' => $falseAlarms, 'hint' => $events->count() > 0 ? round($falseAlarms / max(1, $events->count()) * 100).' %' : null],
        ];

        $list = $events->take(self::LIST_LIMIT)->map(function (NormalizedEvent $event) use ($incidents, $context): array {
            $incident = $incidents->get($event->id);

            return [
                'id' => $event->id,
                'reference' => $incident?->reference(),
                'title' => 'Botón de pánico',
                'severity' => 'critical',
                'assetCode' => $event->asset?->code ?? $event->asset?->name,
                'driverName' => $event->driver?->full_name,
                'occurredAt' => $event->occurred_at->toIso8601String(),
                'statusLabel' => $incident !== null
                    ? IncidentStatusPresenter::labelForIncident($incident)
                    : 'Sin incidente',
                'href' => $incident !== null
                    ? CopilotPresenter::incidentHref($context->teamSlug, $incident->id)
                    : CopilotPresenter::eventHref($context->teamSlug, $event->id),
            ];
        })->values()->all();

        $blocks = [
            ['type' => 'kpis', 'items' => $kpis],
            [
                'type' => 'bars',
                'title' => "Botones de pánico por día · {$period->label}",
                'total' => $events->count(),
                'tone' => 'critical',
                'items' => $bars,
                'legend' => $topAssets->all(),
            ],
        ];

        if ($list !== []) {
            $blocks[] = ['type' => 'events', 'title' => 'Últimos botones de pánico', 'items' => $list];
        }

        $scope = $context->asset !== null ? ' de '.CopilotPresenter::assetLabel($context->asset) : '';
        $highlights = [$events->count() === 0
            ? "No hubo botones de pánico{$scope} en {$period->label}."
            : "Se registraron {$events->count()} botón(es) de pánico{$scope} en {$period->label}, {$today} hoy."];

        if ($avgResponseSeconds !== null) {
            $highlights[] = 'Tiempo medio de respuesta del monitoreo: '.$this->formatDuration($avgResponseSeconds).'.';
        }

        if ($open > 0) {
            $highlights[] = "{$open} siguen abiertos y requieren atención.";
        }

        if ($topAssets->isNotEmpty() && $context->asset === null) {
            $first = $topAssets->first();
            $highlights[] = "La unidad con más pánicos es {$first['label']} ({$first['value']}).";
        }

        return new CopilotToolResult(
            tool: 'panic_kpis',
            label: 'Botón de pánico',
            blocks: $blocks,
            sources: array_values($incidents->take(8)->map(fn (Incident $i) => [
                'kind' => 'incident',
                'id' => $i->id,
                'label' => $i->title,
                'href' => CopilotPresenter::incidentHref($context->teamSlug, $i->id),
            ])->all()),
            facts: [
                'period' => $period->label,
                'total' => $events->count(),
                'today' => $today,
                'open' => $open,
                'false_alarms' => $falseAlarms,
                'avg_response_seconds' => $avgResponseSeconds,
                'top_assets' => $topAssets->all(),
                'latest' => array_slice($list, 0, 3),
            ],
            highlights: $highlights,
        );
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} s";
        }

        if ($seconds < 3600) {
            return round($seconds / 60, 1).' min';
        }

        return round($seconds / 3600, 1).' h';
    }
}
