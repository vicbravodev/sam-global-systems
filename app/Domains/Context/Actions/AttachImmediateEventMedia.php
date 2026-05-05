<?php

namespace App\Domains\Context\Actions;

use App\Contracts\ObjectStorage;
use App\Domains\Context\Enums\MediaAvailabilityStatus;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Enums\MediaRole;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Ingestion\Enums\AttachmentType;
use App\Domains\Ingestion\Models\RawEventAttachment;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\FileObject;
use Illuminate\Support\Collection;

class AttachImmediateEventMedia
{
    public function __construct(
        private readonly ObjectStorage $storage,
    ) {}

    /**
     * Materialize an `EventMediaContext` row for every raw attachment associated
     * with the underlying raw event, copying the binary into the canonical
     * `teams/{teamId}/events/{normalizedEventId}/media/{filename}` path on the
     * configured object storage and registering a `FileObject` for accounting.
     *
     * Idempotent: re-running for the same event will reuse existing rows
     * (matched on `(normalized_event_id, storage_path)`) and skip uploads when
     * the canonical object already exists.
     *
     * @return Collection<int, EventMediaContext>
     */
    public function execute(NormalizedEvent $normalizedEvent): Collection
    {
        $rawEvent = $normalizedEvent->rawEvent;

        if ($rawEvent === null) {
            return collect();
        }

        $attachments = $rawEvent->attachments()->get();

        if ($attachments->isEmpty()) {
            return collect();
        }

        return $attachments
            ->map(fn (RawEventAttachment $attachment) => $this->materialize($normalizedEvent, $attachment))
            ->filter()
            ->values();
    }

    private function materialize(NormalizedEvent $normalizedEvent, RawEventAttachment $attachment): ?EventMediaContext
    {
        $filename = basename($attachment->storage_path);
        $canonicalPath = sprintf(
            'teams/%d/events/%d/media/%s',
            $normalizedEvent->team_id,
            $normalizedEvent->id,
            $filename,
        );

        if (! $this->storage->exists($canonicalPath)) {
            $contents = $this->storage->get($attachment->storage_path);

            if ($contents === null) {
                return null;
            }

            $this->storage->put($canonicalPath, $contents, [
                'visibility' => 'private',
                'ContentType' => $attachment->mime_type,
            ]);
        }

        $sizeBytes = $this->storage->size($canonicalPath) ?? $attachment->size_bytes;
        $mimeType = $attachment->mime_type ?? $this->storage->mimeType($canonicalPath);

        $fileObject = FileObject::withoutGlobalScopes()->firstOrCreate(
            [
                'bucket' => config('filesystems.disks.rustfs.bucket', 'sam'),
                'object_key' => $canonicalPath,
            ],
            [
                'team_id' => $normalizedEvent->team_id,
                'original_filename' => $filename,
                'size_bytes' => $sizeBytes ?? 0,
                'content_type' => $mimeType,
                'visibility' => 'private',
                'category' => 'media',
                'fileable_type' => null,
                'fileable_id' => null,
                'metadata_json' => [
                    'source_attachment_id' => $attachment->id,
                ],
            ],
        );

        $media = EventMediaContext::withoutGlobalScopes()->firstOrCreate(
            [
                'normalized_event_id' => $normalizedEvent->id,
                'storage_path' => $canonicalPath,
            ],
            [
                'team_id' => $normalizedEvent->team_id,
                'asset_id' => $normalizedEvent->asset_id,
                'provider_id' => $normalizedEvent->provider_id,
                'file_object_id' => $fileObject->id,
                'source_attachment_id' => $attachment->id,
                'media_type' => $this->resolveMediaType($attachment->attachment_type),
                'media_role' => MediaRole::PrimaryEvidence,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'captured_at' => $normalizedEvent->occurred_at ?? $normalizedEvent->rawEvent?->occurred_at ?? null,
                'availability_status' => MediaAvailabilityStatus::Available,
                'retrieval_status' => MediaRetrievalStatus::Ready,
                'metadata_json' => $attachment->metadata_json ?? null,
            ],
        );

        if ($media->wasRecentlyCreated) {
            EventMediaAvailable::dispatch($media, $normalizedEvent);
        }

        FileObject::withoutGlobalScopes()
            ->whereKey($fileObject->id)
            ->update([
                'fileable_type' => EventMediaContext::class,
                'fileable_id' => $media->id,
                'updated_at' => now(),
            ]);

        return $media;
    }

    private function resolveMediaType(?AttachmentType $type): MediaType
    {
        return match ($type) {
            AttachmentType::Snapshot => MediaType::Snapshot,
            AttachmentType::Image => MediaType::Image,
            AttachmentType::Clip => MediaType::Clip,
            default => MediaType::Snapshot,
        };
    }
}
