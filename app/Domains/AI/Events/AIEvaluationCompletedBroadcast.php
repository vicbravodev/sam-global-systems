<?php

namespace App\Domains\AI\Events;

use App\Domains\AI\Models\AIEventEvaluation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AIEvaluationCompletedBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public AIEventEvaluation $evaluation,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->evaluation->team_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.evaluation.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'evaluation_id' => $this->evaluation->id,
            'normalized_event_id' => $this->evaluation->normalized_event_id,
            'classification' => $this->evaluation->classification?->value,
            'priority_level' => $this->evaluation->priority_level?->value,
            'confidence_score' => (float) $this->evaluation->confidence_score,
            'risk_score' => (float) $this->evaluation->risk_score,
            'requires_action' => (bool) $this->evaluation->requires_action,
        ];
    }
}
