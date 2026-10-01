<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fleet events in the period, optionally narrowed by type, severity, unit or
 * category: counts per type and severity plus the most recent ones.
 */
final class SearchEventsTool implements CopilotTool
{
    private const DEFAULT_LIMIT = 10;

    private const MAX_BARS = 8;

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('incidents.view')) {
            return CopilotToolResult::denied('search_events', 'Búsqueda de eventos', 'eventos');
        }

        $period = $context->period;
        $typeCode = $context->arguments['event_type'] ?? null;
        $severityCode = $context->arguments['severity'] ?? null;
        $limit = (int) ($context->arguments['limit'] ?? self::DEFAULT_LIMIT);

        $base = fn (): Builder => NormalizedEvent::query()
            ->where('team_id', $context->teamId)
            ->whereBetween('occurred_at', [$period->from, $period->to])
            ->when($context->asset, fn ($q, $asset) => $q->where('asset_id', $asset->id))
            ->when($context->category, fn ($q, $category) => $q->whereHas('asset', fn ($a) => $a
                ->where('team_id', $context->teamId)
                ->whereHas('assetType', fn ($t) => $t->where('category', $category->value))))
            ->when($typeCode, fn ($q, $code) => $q->whereIn('event_type_id', EventType::query()->where('code', $code)->select('id')))
            ->when($severityCode, fn ($q, $code) => $q->whereIn('event_severity_id', EventSeverity::query()->where('code', $code)->select('id')));

        $typeCounts = $base()->selectRaw('event_type_id, count(*) as total')->groupBy('event_type_id')->pluck('total', 'event_type_id');
        $severityCounts = $base()->selectRaw('event_severity_id, count(*) as total')->groupBy('event_severity_id')->pluck('total', 'event_severity_id');

        $types = EventType::query()->whereIn('id', $typeCounts->keys()->filter())->get(['id', 'code', 'name'])->keyBy('id');
        $severities = EventSeverity::query()->whereIn('id', $severityCounts->keys()->filter())->get(['id', 'code'])->keyBy('id');

        $byType = $typeCounts
            ->mapWithKeys(fn ($total, $id) => [($types->get($id)?->code ?? 'sin_tipo') => (int) $total])
            ->sortDesc();
        $byTypeNames = $typeCounts
            ->map(fn ($total, $id) => ['label' => (string) ($types->get($id)?->name ?? 'Sin tipo'), 'value' => (int) $total])
            ->sortByDesc('value')
            ->values();
        $bySeverity = $severityCounts
            ->mapWithKeys(fn ($total, $id) => [($severities->get($id)?->code ?? 'sin_severidad') => (int) $total])
            ->sortDesc();
        $total = (int) $typeCounts->sum();

        $scope = $this->describeScope($context, $typeCode, $severityCode);

        if ($total === 0) {
            $text = "No hay eventos{$scope} en {$period->label}.";

            return new CopilotToolResult(
                tool: 'search_events',
                label: 'Búsqueda de eventos',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => $text]],
                facts: ['period' => $period->label, 'total' => 0, 'by_type' => [], 'by_severity' => [], 'recent' => []],
                highlights: [$text],
            );
        }

        $recent = $base()
            ->with(['eventType:id,code,name', 'eventSeverity:id,code', 'asset:id,team_id,code,name', 'driver'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $rows = array_values($recent->map(fn (NormalizedEvent $event) => [
            'id' => (int) $event->id,
            'title' => (string) ($event->eventType?->name ?? 'Evento'),
            'severity' => CopilotPresenter::severity($event->eventSeverity?->code),
            'assetCode' => $event->asset?->code ?? $event->asset?->name,
            'driverName' => $event->driver?->full_name,
            'occurredAt' => $event->occurred_at->toIso8601String(),
            'statusLabel' => (string) ($event->eventType?->name ?? 'Evento'),
            'href' => CopilotPresenter::eventHref($context->teamSlug, (int) $event->id),
        ])->all());

        $topName = $byTypeNames->first()['label'];

        return new CopilotToolResult(
            tool: 'search_events',
            label: 'Búsqueda de eventos',
            blocks: [
                [
                    'type' => 'bars',
                    'title' => "Eventos por tipo · {$period->label}",
                    'total' => $total,
                    'items' => $byTypeNames->take(self::MAX_BARS)->all(),
                    'legend' => [],
                ],
                ['type' => 'events', 'title' => 'Eventos más recientes', 'items' => $rows],
            ],
            sources: array_map(fn (array $row) => [
                'kind' => 'event',
                'id' => $row['id'],
                'label' => trim($row['title'].' · '.($row['assetCode'] ?? ''), ' ·'),
                'href' => $row['href'],
            ], $rows),
            facts: [
                'period' => $period->label,
                'total' => $total,
                'by_type' => $byType->all(),
                'by_severity' => $bySeverity->all(),
                'recent' => $recent->map(fn (NormalizedEvent $event) => [
                    'at' => $event->occurred_at->toIso8601String(),
                    'type' => $event->eventType?->code,
                    'severity' => $event->eventSeverity?->code,
                    'asset' => $event->asset?->code ?? $event->asset?->name,
                ])->values()->all(),
            ],
            highlights: ["Hubo {$total} evento(s){$scope} en {$period->label}; el más frecuente fue ".mb_strtolower($topName).'.'],
        );
    }

    private function describeScope(CopilotToolContext $context, ?string $typeCode, ?string $severityCode): string
    {
        $parts = array_filter([
            $typeCode ? "de tipo {$typeCode}" : null,
            $severityCode ? "de severidad {$severityCode}" : null,
            $context->asset ? 'de '.CopilotPresenter::assetLabel($context->asset) : null,
        ]);

        return $parts === [] ? '' : ' '.implode(' ', $parts);
    }
}
