<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotMediaUrls;
use App\Domains\Copilot\Support\CopilotPresenter;

/**
 * Latest camera media (video clips, snapshots) captured for a unit.
 */
final class AssetMediaTool implements CopilotTool
{
    private const LIMIT = 6;

    /**
     * @var array<string, string>
     */
    private const ROLE_LABELS = [
        'primary_evidence' => 'Evidencia principal',
        'supporting_evidence' => 'Evidencia de apoyo',
        'pre_event_context' => 'Antes del evento',
        'post_event_context' => 'Después del evento',
        'driver_facing' => 'Cámara de cabina',
        'road_facing' => 'Cámara frontal',
        'cabin_audio' => 'Audio de cabina',
    ];

    public function __construct(private readonly CopilotMediaUrls $urls) {}

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('context.view')) {
            return CopilotToolResult::denied('asset_media', 'Archivo de media', 'la media de cámaras');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $label = CopilotPresenter::assetLabel($asset);

        $media = EventMediaContext::query()
            ->where('team_id', $context->teamId)
            ->where('asset_id', $asset->id)
            ->with('normalizedEvent.eventType')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        if ($media->isEmpty()) {
            return new CopilotToolResult(
                tool: 'asset_media',
                label: 'Archivo de media',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => "No hay videos ni snapshots registrados para {$label}."]],
                facts: ['asset' => $label, 'media_count' => 0],
                highlights: ["No encontré media para {$label}."],
            );
        }

        $items = $media->map(function (EventMediaContext $item) use ($context): array {
            $event = $item->normalizedEvent;

            $url = $this->urls->url($item);

            return [
                'id' => (int) $item->id,
                'mediaType' => $item->media_type?->value,
                'role' => $item->media_role?->value,
                'roleLabel' => $item->media_role ? (self::ROLE_LABELS[$item->media_role->value] ?? $item->media_role->value) : null,
                'url' => $url,
                'thumbnailUrl' => $this->urls->thumbnail($item, $url),
                'mimeType' => $item->mime_type,
                'durationSeconds' => $item->duration_seconds,
                'capturedAt' => $item->captured_at?->toIso8601String(),
                'availability' => $item->availability_status?->value,
                'eventType' => $event?->eventType?->name,
                'eventHref' => $event ? CopilotPresenter::eventHref($context->teamSlug, (int) $event->id) : null,
            ];
        })->all();

        $latest = $items[0];
        $kind = in_array($latest['mediaType'], ['video', 'clip'], true) ? 'un video' : 'una imagen';

        return new CopilotToolResult(
            tool: 'asset_media',
            label: 'Archivo de media',
            blocks: [[
                'type' => 'media',
                'assetId' => (int) $asset->id,
                'assetLabel' => $label,
                'items' => $items,
                'href' => CopilotPresenter::assetHref($context->teamSlug, (int) $asset->id),
            ]],
            sources: $media
                ->filter(fn (EventMediaContext $m) => $m->normalizedEvent !== null)
                ->map(fn (EventMediaContext $m) => [
                    'kind' => 'event',
                    'id' => (int) $m->normalized_event_id,
                    'label' => ($m->normalizedEvent->eventType?->name ?? 'Evento').' · '.$m->captured_at?->format('d/m H:i'),
                    'href' => CopilotPresenter::eventHref($context->teamSlug, (int) $m->normalized_event_id),
                ])
                ->values()
                ->all(),
            facts: [
                'asset' => $label,
                'media_count' => count($items),
                'latest' => [
                    'type' => $latest['mediaType'],
                    'camera' => $latest['roleLabel'],
                    'captured_at' => $latest['capturedAt'],
                    'event' => $latest['eventType'],
                ],
            ],
            highlights: [
                "La media más reciente de {$label} es {$kind}"
                    .($latest['eventType'] ? " del evento «{$latest['eventType']}»" : '')
                    .', '.CopilotPresenter::describeAge($latest['capturedAt']).'.',
            ],
        );
    }
}
