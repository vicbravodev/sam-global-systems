<?php

namespace App\Domains\Copilot\Tools;

use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Support\MediaFileVerdicts;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Support\EventMediaGallery;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Data\CopilotToolResult;
use App\Domains\Copilot\Support\CopilotPresenter;
use App\Domains\Incidents\Models\Incident;

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

    public function __construct(private readonly EventMediaGallery $gallery) {}

    public function run(CopilotToolContext $context): CopilotToolResult
    {
        if (! $context->can('context.view')) {
            return CopilotToolResult::denied('asset_media', 'Archivo de media', 'la media de cámaras');
        }

        $asset = $context->asset;
        abort_if($asset === null || $asset->team_id !== $context->teamId, 404);

        $label = CopilotPresenter::assetLabel($asset);

        // Frames cut out of a clip are rows too; read enough to fold them
        // under their clip and still return LIMIT real files.
        $rows = EventMediaContext::query()
            ->where('team_id', $context->teamId)
            ->where('asset_id', $asset->id)
            ->with('normalizedEvent.eventType')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT * 8)
            ->get();

        $entries = array_slice($this->gallery->entries($rows), 0, self::LIMIT);
        $media = collect($entries)->map(fn (array $entry): EventMediaContext => $entry['media']);

        if ($media->isEmpty()) {
            return new CopilotToolResult(
                tool: 'asset_media',
                label: 'Archivo de media',
                blocks: [['type' => 'notice', 'tone' => 'info', 'text' => "No hay videos ni snapshots registrados para {$label}."]],
                facts: ['asset' => $label, 'media_count' => 0],
                highlights: ["No encontré media para {$label}."],
            );
        }

        $verdicts = $this->latestVerdicts($entries);
        $incidents = Incident::query()
            ->where('team_id', $context->teamId)
            ->whereIn('related_event_id', $media->pluck('normalized_event_id')->unique()->all())
            ->get(['id', 'number', 'related_event_id'])
            ->keyBy('related_event_id');

        $items = array_map(function (array $entry) use ($context, $verdicts, $incidents): array {
            /** @var EventMediaContext $item */
            $item = $entry['media'];
            $event = $item->normalizedEvent;
            $verdict = $verdicts[(int) $item->id] ?? null;
            $incident = $incidents->get($item->normalized_event_id);

            return [
                'id' => (int) $item->id,
                'mediaType' => $item->media_type?->value,
                'role' => $item->media_role?->value,
                'roleLabel' => $this->cameraLabel($item),
                'url' => $entry['url'],
                'thumbnailUrl' => $entry['thumbnailUrl'] ?? (EventMediaGallery::isVideo($item) ? null : $entry['url']),
                'mimeType' => $item->mime_type,
                'durationSeconds' => $item->duration_seconds,
                'capturedAt' => $item->captured_at?->toIso8601String(),
                'availability' => $item->availability_status?->value,
                'eventType' => $event?->eventType?->name,
                'eventHref' => $event ? CopilotPresenter::eventHref($context->teamSlug, (int) $event->id) : null,
                'incident' => $incident?->reference(),
                'incidentHref' => $incident ? CopilotPresenter::incidentHref($context->teamSlug, (int) $incident->id) : null,
                'aiVerdict' => $verdict?->result?->value,
                'aiVerdictLabel' => $verdict?->result?->label(),
                'aiSummary' => $verdict?->summary_text,
            ];
        }, $entries);

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
                ->unique('normalized_event_id')
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
                    'incident' => $latest['incident'],
                ],
                // What the vision model saw in each file: the only way the
                // agent can describe footage it cannot open itself.
                'items' => array_map(fn (array $item): array => [
                    'type' => $item['mediaType'],
                    'camera' => $item['roleLabel'],
                    'captured_at' => $item['capturedAt'],
                    'event' => $item['eventType'],
                    'incident' => $item['incident'],
                    'ai_verdict' => $item['aiVerdictLabel'],
                    'ai_saw' => $item['aiSummary'],
                ], $items),
                'assessed_count' => count(array_filter($items, fn (array $item): bool => $item['aiVerdict'] !== null)),
            ],
            highlights: array_values(array_filter([
                "La media más reciente de {$label} es {$kind}"
                    .($latest['eventType'] ? " del evento «{$latest['eventType']}»" : '')
                    .($latest['incident'] ? " ({$latest['incident']})" : '')
                    .', '.CopilotPresenter::describeAge($latest['capturedAt']).'.',
                $latest['aiSummary'] ? 'Lo que la IA vio: '.$latest['aiSummary'] : null,
            ])),
        );
    }

    /**
     * @param  list<array{media: EventMediaContext, url: string|null, thumbnailUrl: string|null, frameIds: list<int>}>  $entries
     * @return array<int, AIMediaAssessment>
     */
    private function latestVerdicts(array $entries): array
    {
        $files = [];

        foreach ($entries as $entry) {
            $files[(int) $entry['media']->id] = [(int) $entry['media']->id, ...$entry['frameIds']];
        }

        return MediaFileVerdicts::forFiles(
            AIMediaAssessment::query()->whereIn('event_media_context_id', array_merge(...array_values($files)))->get(),
            $files,
        );
    }

    /**
     * Uploaded panic/safety footage keeps the camera in `metadata_json.input`;
     * the media role only says "primary evidence".
     */
    private function cameraLabel(EventMediaContext $media): ?string
    {
        $input = is_array($media->metadata_json) ? ($media->metadata_json['input'] ?? null) : null;

        return match ($input) {
            'dashcamRoadFacing' => self::ROLE_LABELS['road_facing'],
            'dashcamDriverFacing' => self::ROLE_LABELS['driver_facing'],
            default => $media->media_role ? (self::ROLE_LABELS[$media->media_role->value] ?? $media->media_role->value) : null,
        };
    }
}
