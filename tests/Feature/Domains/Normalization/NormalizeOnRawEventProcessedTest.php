<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Events\RawEventProcessed;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NormalizeOnRawEventProcessedTest extends TestCase
{
    use RefreshDatabase;

    private function createRawEvent(): RawEvent
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        return RawEvent::factory()->create([
            'team_id' => $team->id,
            'status' => RawEventStatus::Processed,
        ]);
    }

    public function test_raw_event_processed_queues_normalization_job(): void
    {
        Queue::fake();

        $rawEvent = $this->createRawEvent();

        RawEventProcessed::dispatch($rawEvent);

        Queue::assertPushed(NormalizeEventJob::class, function (NormalizeEventJob $job) use ($rawEvent) {
            return $job->rawEventId === $rawEvent->id
                && $job->queue === 'normalization';
        });
    }
}
