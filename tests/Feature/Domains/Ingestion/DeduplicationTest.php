<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Actions\DetectDuplicateEvent;
use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Events\RawEventDuplicated;
use App\Domains\Ingestion\Models\EventDeduplicationKey;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private function createEventSource(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $eventSource = EventSource::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => null,
            'source_type' => EventSourceType::Webhook,
            'source_name' => 'webhook',
            'status' => EventSourceStatus::Active,
        ]);

        return [$user, $team, $eventSource];
    }

    public function test_duplicate_event_detected_and_marked(): void
    {
        Event::fake([RawEventDuplicated::class]);

        [, $team, $eventSource] = $this->createEventSource();

        $firstEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'payload_json' => ['eventType' => 'Test'],
            'received_at' => now(),
            'status' => RawEventStatus::Received,
            'deduplication_key' => 'dedup-key-001',
            'checksum' => hash('sha256', json_encode(['eventType' => 'Test'])),
        ]);

        $action = app(DetectDuplicateEvent::class);
        $isFirstDuplicate = $action->execute($firstEvent);

        $this->assertFalse(
            $isFirstDuplicate,
            'The first event with a deduplication key should NOT be detected as a duplicate',
        );

        $secondEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'payload_json' => ['eventType' => 'Test'],
            'received_at' => now(),
            'status' => RawEventStatus::Received,
            'deduplication_key' => 'dedup-key-001',
            'checksum' => hash('sha256', json_encode(['eventType' => 'Test'])),
        ]);

        $isSecondDuplicate = $action->execute($secondEvent);

        $this->assertTrue(
            $isSecondDuplicate,
            'The second event with the same deduplication key should be detected as a duplicate',
        );

        $secondEvent->refresh();

        $this->assertEquals(
            RawEventStatus::DuplicateDetected,
            $secondEvent->status,
            'Duplicate event status should be updated to "duplicate_detected"',
        );

        Event::assertDispatched(RawEventDuplicated::class, function ($event) use ($secondEvent) {
            return $event->rawEvent->id === $secondEvent->id
                && $event->deduplicationKey === 'dedup-key-001';
        });
    }

    public function test_deduplication_keys_expire_after_ttl(): void
    {
        Event::fake([RawEventDuplicated::class]);

        [, $team, $eventSource] = $this->createEventSource();

        $firstEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'payload_json' => ['eventType' => 'Expiry'],
            'received_at' => now()->subDays(2),
            'status' => RawEventStatus::Processed,
            'deduplication_key' => 'expiring-key',
            'checksum' => hash('sha256', json_encode(['eventType' => 'Expiry'])),
        ]);

        EventDeduplicationKey::create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'deduplication_key' => 'expiring-key',
            'raw_event_id' => $firstEvent->id,
            'first_seen_at' => now()->subDays(2),
            'expires_at' => now()->subDay(),
        ]);

        $newEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'payload_json' => ['eventType' => 'Expiry'],
            'received_at' => now(),
            'status' => RawEventStatus::Received,
            'deduplication_key' => 'expiring-key',
            'checksum' => hash('sha256', json_encode(['eventType' => 'Expiry'])),
        ]);

        $action = app(DetectDuplicateEvent::class);
        $isDuplicate = $action->execute($newEvent);

        $this->assertFalse(
            $isDuplicate,
            'Expired deduplication keys should not block new events with the same key',
        );

        Event::assertNotDispatched(
            RawEventDuplicated::class,
            'RawEventDuplicated should NOT be dispatched when deduplication key has expired',
        );
    }
}
