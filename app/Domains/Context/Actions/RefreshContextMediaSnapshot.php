<?php

namespace App\Domains\Context\Actions;

use App\Domains\Context\Enums\MediaAvailabilityStatus;
use App\Domains\Context\Enums\MediaRetrievalStatus;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Support\SignalsBuilder;
use Illuminate\Support\Collection;

class RefreshContextMediaSnapshot
{
    /**
     * Re-read the media inventory for a normalized event and project it into
     * `EventContextSnapshot.media_snapshot_json`. Recomputes media-aware
     * signals on `signals_json` and bumps `context_version` so downstream
     * consumers can detect that the snapshot changed asynchronously.
     */
    public function execute(int $normalizedEventId): ?EventContextSnapshot
    {
        $snapshot = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEventId)
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $media = EventMediaContext::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEventId)
            ->get();

        $mediaSnapshot = $this->serializeMedia($media);

        $signals = SignalsBuilder::build([
            'geofence_matches' => $this->geofenceMatches($snapshot),
            'incidents' => $snapshot->incidents_snapshot_json ?? [],
            'recent_history' => $snapshot->recent_history_snapshot_json ?? [],
            'driver' => $snapshot->driver_snapshot_json ?? [],
            'asset' => $snapshot->asset_snapshot_json ?? [],
            'telemetry' => $snapshot->telemetry_snapshot_json ?? [],
            'media' => $mediaSnapshot,
            'outside_operating_hours' => (bool) ($snapshot->signals_json['outside_operating_hours'] ?? false),
            'event' => [
                // Re-read from the prior signals so an async media refresh
                // never flips the resolution flag computed at build time.
                'is_resolved' => ($snapshot->signals_json['external_resolved'] ?? false) === true ? true : null,
            ],
        ]);

        $snapshot->forceFill([
            'media_snapshot_json' => $mediaSnapshot,
            'signals_json' => $signals,
            'context_version' => (int) $snapshot->context_version + 1,
        ])->save();

        return $snapshot->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeMedia(Collection $media): array
    {
        return $media->map(fn (EventMediaContext $row) => [
            'id' => $row->id,
            'type' => $row->media_type instanceof \BackedEnum ? $row->media_type->value : $row->media_type,
            'role' => $row->media_role instanceof \BackedEnum ? $row->media_role->value : $row->media_role,
            'storage_path' => $row->storage_path,
            'media_url' => $row->media_url,
            'thumbnail_url' => $row->thumbnail_url,
            'mime_type' => $row->mime_type,
            'size_bytes' => $row->size_bytes,
            'duration_seconds' => $row->duration_seconds,
            'availability_status' => $row->availability_status instanceof MediaAvailabilityStatus
                ? $row->availability_status->value
                : $row->availability_status,
            'retrieval_status' => $row->retrieval_status instanceof MediaRetrievalStatus
                ? ($row->retrieval_status === MediaRetrievalStatus::Ready ? 'available' : $row->retrieval_status->value)
                : $row->retrieval_status,
            'captured_at' => $row->captured_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function geofenceMatches(EventContextSnapshot $snapshot): array
    {
        $matches = $snapshot->geofence_snapshot_json;

        return is_array($matches) ? $matches : [];
    }
}
