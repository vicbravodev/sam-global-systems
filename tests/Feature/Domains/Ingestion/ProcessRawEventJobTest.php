<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Actions\DetectDuplicateEvent;
use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Events\RawEventFailed;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProcessRawEventJobTest extends TestCase
{
    use RefreshDatabase;

    private function createRawEvent(): array
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

        $rawEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'payload_json' => ['eventType' => 'AlertIncident', 'eventId' => 'proc-001'],
            'received_at' => now(),
            'status' => RawEventStatus::PendingProcessing,
            'deduplication_key' => 'proc-001',
            'checksum' => hash('sha256', json_encode(['eventType' => 'AlertIncident', 'eventId' => 'proc-001'])),
        ]);

        return [$user, $team, $eventSource, $rawEvent];
    }

    public function test_raw_event_dispatches_to_normalization_queue(): void
    {
        [, , , $rawEvent] = $this->createRawEvent();

        $job = new ProcessRawEventJob($rawEvent->id);

        $this->assertEquals(
            'ingestion',
            $job->queue,
            'ProcessRawEventJob should be dispatched to the "ingestion" queue',
        );

        $job->handle(app(DetectDuplicateEvent::class));

        $rawEvent->refresh();

        $this->assertEquals(
            RawEventStatus::Processed,
            $rawEvent->status,
            'RawEvent should be marked as "processed" after successful job execution',
        );

        $this->assertEquals(
            1,
            $rawEvent->processing_attempts,
            'processing_attempts should be incremented to 1 after first processing',
        );

        $this->assertNotNull(
            $rawEvent->last_processing_attempt_at,
            'last_processing_attempt_at should be set after processing',
        );
    }

    public function test_processing_attempts_increment_on_retry(): void
    {
        [, , , $rawEvent] = $this->createRawEvent();

        $rawEvent->update([
            'processing_attempts' => 1,
            'last_processing_attempt_at' => now()->subMinutes(5),
        ]);

        $job = new ProcessRawEventJob($rawEvent->id);
        $job->handle(app(DetectDuplicateEvent::class));

        $rawEvent->refresh();

        $this->assertEquals(
            2,
            $rawEvent->processing_attempts,
            'processing_attempts should increment on each retry — started at 1, should now be 2',
        );

        $this->assertNotNull(
            $rawEvent->last_processing_attempt_at,
            'last_processing_attempt_at should be updated on each retry',
        );
    }

    public function test_duplicate_event_stops_processing(): void
    {
        [, $team, $eventSource, $rawEvent] = $this->createRawEvent();

        $action = app(DetectDuplicateEvent::class);
        $action->execute($rawEvent);

        $secondEvent = RawEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'event_source_id' => $eventSource->id,
            'payload_json' => ['eventType' => 'AlertIncident', 'eventId' => 'proc-001'],
            'received_at' => now(),
            'status' => RawEventStatus::PendingProcessing,
            'deduplication_key' => 'proc-001',
            'checksum' => hash('sha256', json_encode(['eventType' => 'AlertIncident', 'eventId' => 'proc-001'])),
        ]);

        $job = new ProcessRawEventJob($secondEvent->id);
        $job->handle(app(DetectDuplicateEvent::class));

        $secondEvent->refresh();

        $this->assertEquals(
            RawEventStatus::DuplicateDetected,
            $secondEvent->status,
            'Duplicate event should be marked as "duplicate_detected" and not proceed to processing',
        );
    }

    public function test_failed_job_marks_event_as_failed_and_dispatches_event(): void
    {
        Event::fake([RawEventFailed::class]);

        [, , , $rawEvent] = $this->createRawEvent();

        $job = new ProcessRawEventJob($rawEvent->id);
        $job->failed(new \RuntimeException('Processing timeout'));

        $rawEvent->refresh();

        $this->assertEquals(
            RawEventStatus::Failed,
            $rawEvent->status,
            'RawEvent should be marked as "failed" when the job fails after all retries',
        );

        Event::assertDispatched(RawEventFailed::class, function ($event) use ($rawEvent) {
            return $event->rawEvent->id === $rawEvent->id
                && $event->reason === 'Processing timeout';
        });
    }
}
