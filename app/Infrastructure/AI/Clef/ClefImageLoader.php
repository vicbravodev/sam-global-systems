<?php

namespace App\Infrastructure\AI\Clef;

use App\Contracts\ObjectStorage;
use App\Domains\AI\Support\ImageSignature;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Support\SystemLog;

/**
 * Junta hasta `ai.clef.max_images` imágenes listas del evento, dentro de los
 * límites de la API de Clef (PNG/JPEG/WebP, tamaño por imagen y total). Lo
 * que no cabe se omite y se registra; nunca hace fallar la llamada.
 * Se invoca dentro del TenantContext del evento.
 */
class ClefImageLoader
{
    private const array ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly ObjectStorage $storage) {}

    /**
     * @return array{images: list<array{content_type: string, base64: string}>, skipped: array<string, int>}
     */
    public function forEvent(int $evaluationId, int $normalizedEventId): array
    {
        $maxImages = (int) config('ai.clef.max_images', 4);
        $maxBytes = (int) config('ai.clef.max_image_bytes', 4 * 1024 * 1024);
        $budget = (int) config('ai.clef.max_total_image_bytes', 8 * 1024 * 1024);

        $media = EventMediaContext::query()
            ->where('normalized_event_id', $normalizedEventId)
            ->whereIn('media_type', [MediaType::Image, MediaType::Snapshot])
            ->where('retrieval_status', MediaRetrievalStatus::Ready)
            ->whereNotNull('storage_path')
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();

        $images = [];
        $skipped = [];
        $used = 0;

        foreach ($media as $item) {
            $bytes = $this->storage->get((string) $item->storage_path);
            $mime = $bytes !== null && $bytes !== '' ? ImageSignature::detect($bytes) : null;

            $reason = match (true) {
                $bytes === null || $bytes === '' => 'missing',
                $mime === null || ! in_array($mime, self::ACCEPTED, true) => 'unsupported_type',
                strlen($bytes) > $maxBytes => 'oversize',
                count($images) >= $maxImages => 'max_images',
                $used + strlen($bytes) > $budget => 'total_budget',
                default => null,
            };

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $used += strlen($bytes);
            $images[] = ['content_type' => $mime, 'base64' => base64_encode($bytes)];
        }

        if ($skipped !== []) {
            SystemLog::skipped(
                'ai.clef_shadow.image_skipped',
                reason: 'limits',
                input: ['evaluation_id' => $evaluationId],
                calc: [
                    'candidates' => $media->count(),
                    'sent' => count($images),
                    'skipped_by_reason' => $skipped,
                    'max_images' => $maxImages,
                    'max_image_bytes' => $maxBytes,
                    'max_total_image_bytes' => $budget,
                    'bytes_sent' => $used,
                ],
            );
        }

        return ['images' => $images, 'skipped' => $skipped];
    }
}
