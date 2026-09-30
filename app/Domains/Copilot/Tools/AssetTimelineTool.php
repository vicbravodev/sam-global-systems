<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Copilot\Support\IdleTimeCalculator;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What happened to one unit in the period, in order: its events, the
 * incidents opened on it and every idle stretch of 10 minutes or more.
 */
final class AssetTimelineTool implements CopilotTool
{
    public const MAX_ITEMS = 40;

    /** Items the UI card shows; the model still reads up to MAX_ITEMS. */
    public const BLOCK_ITEMS = 12;

    public const MIN_IDLE_MINUTES = 10;

    public function __construct(private readonly IdleTimeCalculator $idle) {}

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('incidents.view')) {
            return CopilotToolResult::denied('asset_timeline', 'Línea de tiempo de unidad', 'incidentes');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $period = $context->period;
        $label = CopilotPresenter::assetLabel($asset);

        $events = NormalizedEvent::query()
            ->where('team_id', $context->teamId)
            ->where('asset_id', $asset->id)
            ->whereBetween('occurred_at', [$period->from, $period->to])
            ->with(['eventType:id,code,name', 'eventSeverity:id,code'])
            ->orderByDesc('occurred_at')
            ->limit(self::MAX_ITEMS)
            ->get(['id', 'event_type_id', 'event_severity_id', 'occurred_at']);

        $incidents = Incident::query()
            ->where('team_id', $context->teamId)
            ->where('asset_id', $asset->id)
            ->whereBetween('opened_at', [$period->from, $period->to])
            ->with('priority')
            ->orderByDesc('opened_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        $idle = collect($this->idle->forAsset($asset, $period->from, $period->to)->segments)
            ->filter(fn (array $segment) => $segment['minutes'] >= self::MIN_IDLE_MINUTES);

        $items = collect()
            ->concat($events->map(fn (NormalizedEvent $e) => [
                'at' => $e->occurred_at->toIso8601String(),
                'kind' => 'event',
                'label' => (string) ($e->eventType?->name ?? 'Evento'),
                'severity' => CopilotPresenter::severity($e->eventSeverity?->code),
                'href' => CopilotPresenter::eventHref($context->teamSlug, (int) $e->id),
            ]))
            ->concat($incidents->map(fn (Incident $i) => [
                'at' => $i->opened_at->toIso8601String(),
                'kind' => 'incident',
                'label' => $i->reference().' · '.$i->title,
                'severity' => CopilotPresenter::severity($i->priority?->code),
                'href' => CopilotPresenter::incidentHref($context->teamSlug, (int) $i->id),
            ]))
            ->concat($idle->map(fn (array $segment) => [
                'at' => $segment['from'],
                'kind' => 'idle',
                'label' => 'Ralentí',
                'minutes' => $segment['minutes'],
            ]))
            ->sortByDesc(fn (array $item) => CarbonImmutable::parse($item['at'])->getTimestamp())
            ->take(self::MAX_ITEMS)
            ->sortBy(fn (array $item) => CarbonImmutable::parse($item['at'])->getTimestamp())
            ->values();

        if ($items->isEmpty()) {
            $text = "{$label} no tiene eventos, incidentes ni ralentí prolongado en {$period->label}.";

            return new CopilotToolResult(
                tool: 'asset_timeline',
                label: 'Línea de tiempo de unidad',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => $text]],
                facts: ['asset' => $label, 'period' => $period->label, 'items' => []],
                highlights: [$text],
            );
        }

        $counts = $items->countBy('kind');
        $idleMinutes = (int) $items->where('kind', 'idle')->sum('minutes');

        return new CopilotToolResult(
            tool: 'asset_timeline',
            label: 'Línea de tiempo de unidad',
            blocks: [[
                'type' => 'timeline',
                'items' => $this->cardItems($items),
                'total' => $items->count(),
                'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $asset->id),
            ]],
            sources: $incidents->take(5)->map(fn (Incident $i) => [
                'kind' => 'incident',
                'id' => (int) $i->id,
                'label' => $i->reference().' · '.$i->title,
                'href' => CopilotPresenter::incidentHref($context->teamSlug, (int) $i->id),
            ])->values()->all(),
            facts: [
                'asset' => $label,
                'period' => $period->label,
                'events' => (int) ($counts['event'] ?? 0),
                'incidents' => (int) ($counts['incident'] ?? 0),
                'idle_stretches' => (int) ($counts['idle'] ?? 0),
                'idle_minutes' => $idleMinutes,
                'items' => $items->map(fn (array $item) => array_diff_key($item, ['href' => true]))->all(),
            ],
            highlights: ["{$label} en {$period->label}: ".($counts['event'] ?? 0).' evento(s), '
                .($counts['incident'] ?? 0).' incidente(s) y '.($counts['idle'] ?? 0).' tramo(s) de ralentí de '.self::MIN_IDLE_MINUTES.' min o más.'],
        );
    }

    /**
     * At most BLOCK_ITEMS for the card: every incident and idle stretch first,
     * then the most recent events to fill the rest, in chronological order.
     *
     * @param  Collection<int, array<string, mixed>>  $items  chronological
     * @return list<array<string, mixed>>
     */
    private function cardItems(Collection $items): array
    {
        if ($items->count() <= self::BLOCK_ITEMS) {
            return $items->values()->all();
        }

        $priority = $items->filter(fn (array $item) => $item['kind'] !== 'event');
        $room = max(0, self::BLOCK_ITEMS - $priority->count());
        $keep = $priority->keys()->take(-self::BLOCK_ITEMS)
            ->concat($items->filter(fn (array $item) => $item['kind'] === 'event')->keys()->reverse()->take($room))
            ->sort();

        return $keep->map(fn (int $key) => $items[$key])->values()->all();
    }
}
