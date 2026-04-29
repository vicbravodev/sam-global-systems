<?php

namespace App\Domains\Automation\Support;

use App\Domains\Automation\Models\ActionExecution;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ActionExecutedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $actionExecutionId,
        public readonly string $actionType,
        public readonly string $status,
        public readonly ?int $incidentId = null,
    ) {}

    public static function fromModel(ActionExecution $execution): self
    {
        return new self(
            teamId: $execution->team_id,
            actionExecutionId: $execution->id,
            actionType: $execution->action_type->value,
            status: $execution->status->value,
            incidentId: $execution->incident_id,
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
        return 'action.executed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action_execution_id' => $this->actionExecutionId,
            'action_type' => $this->actionType,
            'status' => $this->status,
            'incident_id' => $this->incidentId,
        ];
    }
}
