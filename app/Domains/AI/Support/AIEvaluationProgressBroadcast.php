<?php

namespace App\Domains\AI\Support;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class AIEvaluationProgressBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly string $taskId,
        public readonly string $stage,
        public readonly string $chunk,
        public readonly int $progressPct,
        public readonly ?int $evaluationId = null,
        public readonly ?int $normalizedEventId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("jobs.{$this->taskId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.evaluation_progress';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'task_id' => $this->taskId,
            'stage' => $this->stage,
            'chunk' => $this->chunk,
            'progress_pct' => $this->progressPct,
            'evaluation_id' => $this->evaluationId,
            'normalized_event_id' => $this->normalizedEventId,
        ];
    }
}
