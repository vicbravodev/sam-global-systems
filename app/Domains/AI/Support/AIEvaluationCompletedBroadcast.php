<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Models\AIEventEvaluation;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class AIEvaluationCompletedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $evaluationId,
        public readonly int $normalizedEventId,
        public readonly string $classification,
        public readonly string $priorityLevel,
        public readonly ?float $confidenceScore,
        public readonly ?float $riskScore,
        public readonly bool $requiresAction,
    ) {}

    public static function fromModel(AIEventEvaluation $evaluation): self
    {
        return new self(
            teamId: $evaluation->team_id,
            evaluationId: $evaluation->id,
            normalizedEventId: $evaluation->normalized_event_id,
            classification: $evaluation->classification->value,
            priorityLevel: $evaluation->priority_level->value,
            confidenceScore: $evaluation->confidence_score,
            riskScore: $evaluation->risk_score,
            requiresAction: $evaluation->requires_action,
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.evaluation_completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'evaluation_id' => $this->evaluationId,
            'normalized_event_id' => $this->normalizedEventId,
            'classification' => $this->classification,
            'priority_level' => $this->priorityLevel,
            'confidence_score' => $this->confidenceScore,
            'risk_score' => $this->riskScore,
            'requires_action' => $this->requiresAction,
        ];
    }
}
