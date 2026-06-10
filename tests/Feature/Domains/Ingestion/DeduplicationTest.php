<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Contracts\RawEventIngestion;
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
use Illuminate\Support\Facades\Bus;
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

    public function test_alert_incident_resolution_state_change_passes_dedup(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $payload = fn (bool $isResolved) => [
            'eventType' => 'AlertIncident',
            'eventId' => 'alert-evt-1',
            'data' => ['isResolved' => $isResolved],
        ];

        $service = app(RawEventIngestion::class);
        $service->ingest($team->id, 'samsara', 'AlertIncident', $payload(false));
        $service->ingest($team->id, 'samsara', 'AlertIncident', $payload(true));

        $events = RawEvent::withoutGlobalScopes()
            ->where('external_event_id', 'alert-evt-1')
            ->orderBy('id')
            ->get();

        $this->assertSame('alert-evt-1:open', $events[0]->deduplication_key);
        $this->assertSame('alert-evt-1:resolved', $events[1]->deduplication_key);

        $action = app(DetectDuplicateEvent::class);
        $this->assertFalse($action->execute($events[0]));
        $this->assertFalse(
            $action->execute($events[1]),
            'a resolution state change must pass dedup — it is an update, not a re-delivery',
        );
    }

    public function test_alert_incident_same_resolution_state_is_still_a_duplicate(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $payload = [
            'eventType' => 'AlertIncident',
            'eventId' => 'alert-evt-2',
            'data' => ['isResolved' => false],
        ];

        $service = app(RawEventIngestion::class);
        $service->ingest($team->id, 'samsara', 'AlertIncident', $payload);
        $service->ingest($team->id, 'samsara', 'AlertIncident', $payload);

        $events = RawEvent::withoutGlobalScopes()
            ->where('external_event_id', 'alert-evt-2')
            ->orderBy('id')
            ->get();

        $action = app(DetectDuplicateEvent::class);
        $this->assertFalse($action->execute($events[0]));
        $this->assertTrue(
            $action->execute($events[1]),
            'a re-delivery with the same resolution state must still be detected as a duplicate',
        );
    }

    public function test_events_without_resolution_state_keep_plain_event_id_as_dedup_key(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        app(RawEventIngestion::class)->ingest($team->id, 'samsara', 'AlertIncident', [
            'eventType' => 'AlertIncident',
            'eventId' => 'alert-evt-3',
        ]);

        $event = RawEvent::withoutGlobalScopes()
            ->where('external_event_id', 'alert-evt-3')
            ->firstOrFail();

        $this->assertSame('alert-evt-3', $event->deduplication_key);
    }
}
