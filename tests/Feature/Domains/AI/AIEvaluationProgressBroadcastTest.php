<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Support\AIEvaluationProgressBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class AIEvaluationProgressBroadcastTest extends TestCase
{
    public function test_broadcasts_on_private_jobs_channel_with_task_id(): void
    {
        $event = new AIEvaluationProgressBroadcast(
            taskId: 'task-abc-123',
            stage: 'ai_textual',
            chunk: 'Based on the telemetry data...',
            progressPct: 45,
            evaluationId: 42,
            normalizedEventId: 101,
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-jobs.task-abc-123', $channels[0]->name);
    }

    public function test_broadcasts_with_expected_payload_shape(): void
    {
        $event = new AIEvaluationProgressBroadcast(
            taskId: 'abc-123',
            stage: 'multimodal',
            chunk: 'Reviewing media...',
            progressPct: 70,
            evaluationId: 7,
            normalizedEventId: 8,
        );

        $this->assertSame('ai.evaluation_progress', $event->broadcastAs());
        $this->assertSame([
            'task_id' => 'abc-123',
            'stage' => 'multimodal',
            'chunk' => 'Reviewing media...',
            'progress_pct' => 70,
            'evaluation_id' => 7,
            'normalized_event_id' => 8,
        ], $event->broadcastWith());
    }
}
