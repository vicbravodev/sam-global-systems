<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Ingestion\Events\RawEventDuplicated;
use App\Domains\Ingestion\Models\EventDeduplicationKey;
use App\Domains\Ingestion\Models\RawEvent;

class DetectDuplicateEvent
{
    /**
     * Check whether this event is a duplicate. Returns true if duplicate found.
     */
    public function execute(RawEvent $rawEvent): bool
    {
        $deduplicationKey = $rawEvent->deduplication_key ?? $rawEvent->checksum;

        if ($deduplicationKey === null) {
            return false;
        }

        $existingKey = EventDeduplicationKey::where('event_source_id', $rawEvent->event_source_id)
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if ($existingKey !== null) {
            if ($existingKey->isExpired()) {
                $existingKey->delete();
            } else {
                $rawEvent->markAsDuplicate();

                RawEventDuplicated::dispatch($rawEvent, $deduplicationKey);

                return true;
            }
        }

        EventDeduplicationKey::create([
            'team_id' => $rawEvent->team_id,
            'event_source_id' => $rawEvent->event_source_id,
            'deduplication_key' => $deduplicationKey,
            'raw_event_id' => $rawEvent->id,
            'first_seen_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        return false;
    }
}
