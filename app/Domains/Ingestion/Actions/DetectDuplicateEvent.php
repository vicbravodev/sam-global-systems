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
                return $this->markDuplicate($rawEvent, $deduplicationKey);
            }
        }

        // Insert-or-ignore on the (event_source_id, deduplication_key) unique
        // index: when a provider retries a webhook and two workers pass the
        // check above at once, the loser sees 0 rows and is the duplicate,
        // instead of failing on a unique violation and retrying the job.
        $now = now();

        $inserted = EventDeduplicationKey::query()->insertOrIgnore([
            'team_id' => $rawEvent->team_id,
            'event_source_id' => $rawEvent->event_source_id,
            'deduplication_key' => $deduplicationKey,
            'raw_event_id' => $rawEvent->id,
            'first_seen_at' => $now,
            'expires_at' => $now->copy()->addHours(24),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return $this->markDuplicate($rawEvent, $deduplicationKey);
        }

        return false;
    }

    private function markDuplicate(RawEvent $rawEvent, string $deduplicationKey): bool
    {
        $rawEvent->markAsDuplicate();

        RawEventDuplicated::dispatch($rawEvent, $deduplicationKey);

        return true;
    }
}
