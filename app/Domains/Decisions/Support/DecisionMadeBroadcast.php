<?php

namespace App\Domains\Decisions\Support;

use App\Domains\Decisions\Models\Decision;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class DecisionMadeBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $decisionId,
        public readonly int $normalizedEventId,
        public readonly string $outcomeCode,
        public readonly string $priorityLevel,
        public readonly bool $requiresHumanReview,
        public readonly string $decidedAt,
    ) {}

    public static function fromModel(Decision $decision): self
    {
        return new self(
            teamId: $decision->team_id,
            decisionId: $decision->id,
            normalizedEventId: $decision->normalized_event_id,
            outcomeCode: $decision->decision_code,
            priorityLevel: $decision->priority_level->value,
            requiresHumanReview: $decision->requires_human_review,
            decidedAt: $decision->decided_at->toIso8601String(),
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
        return 'decisions.decision_made';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'decision_id' => $this->decisionId,
            'normalized_event_id' => $this->normalizedEventId,
            'outcome_code' => $this->outcomeCode,
            'priority_level' => $this->priorityLevel,
            'requires_human_review' => $this->requiresHumanReview,
            'decided_at' => $this->decidedAt,
        ];
    }
}
