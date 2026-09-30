<?php

namespace App\Domains\Ingestion\Actions;

use App\Domains\Ingestion\Events\RawEventDuplicated;
use App\Domains\Ingestion\Models\EventDeduplicationKey;
use App\Domains\Ingestion\Models\RawEvent;
use App\Support\SystemLog;

class DetectDuplicateEvent
{
    /**
     * Check whether this event is a duplicate. Returns true if duplicate found.
     */
    public function execute(RawEvent $rawEvent): bool
    {
        $deduplicationKey = $rawEvent->deduplication_key ?? $rawEvent->checksum;
        // Nunca se registra el valor de la clave (puede ser un checksum del payload): sólo su procedencia.
        $dedupSource = $rawEvent->deduplication_key !== null ? 'deduplication_key' : 'checksum';

        if ($deduplicationKey === null) {
            SystemLog::skipped('ingestion.dedup.skipped', reason: 'no_dedup_key', input: ['raw_event_id' => $rawEvent->id]);

            return false;
        }

        $existingKey = EventDeduplicationKey::where('event_source_id', $rawEvent->event_source_id)
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if ($existingKey !== null && (int) $existingKey->raw_event_id === (int) $rawEvent->id) {
            // La clave es del propio evento: es un re-proceso (rescate de
            // atascados o reintento tras pasar el dedup), no un duplicado.
            SystemLog::ok('ingestion.dedup.own_key', input: ['raw_event_id' => $rawEvent->id, 'dedup_source' => $dedupSource]);

            return false;
        }

        if ($existingKey !== null) {
            if ($existingKey->isExpired()) {
                $existingKey->delete();

                SystemLog::ok('ingestion.dedup.key_expired', input: ['raw_event_id' => $rawEvent->id], result: ['expired_key_raw_event_id' => $existingKey->raw_event_id]);
            } else {
                SystemLog::skipped('ingestion.duplicate.detected', reason: 'existing_key', input: ['raw_event_id' => $rawEvent->id, 'dedup_source' => $dedupSource], result: ['first_raw_event_id' => $existingKey->raw_event_id]);

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
            SystemLog::skipped('ingestion.duplicate.detected', reason: 'lost_insert_race', input: ['raw_event_id' => $rawEvent->id, 'dedup_source' => $dedupSource]);

            return $this->markDuplicate($rawEvent, $deduplicationKey);
        }

        SystemLog::ok('ingestion.dedup.key_registered', input: ['raw_event_id' => $rawEvent->id, 'dedup_source' => $dedupSource], calc: ['ttl_hours' => 24]);

        return false;
    }

    private function markDuplicate(RawEvent $rawEvent, string $deduplicationKey): bool
    {
        $rawEvent->markAsDuplicate();

        RawEventDuplicated::dispatch($rawEvent, $deduplicationKey);

        return true;
    }
}
