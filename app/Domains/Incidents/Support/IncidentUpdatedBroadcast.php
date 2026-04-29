<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Models\Incident;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class IncidentUpdatedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $incidentId,
        public readonly string $status,
        public readonly string $priority,
        public readonly ?string $assignedTo,
        public readonly string $updatedAt,
    ) {}

    public static function fromModel(Incident $incident, ?string $assignedTo = null): self
    {
        $incident->loadMissing(['priority', 'status']);

        return new self(
            teamId: (int) $incident->team_id,
            incidentId: (int) $incident->id,
            status: (string) ($incident->status?->code ?? ''),
            priority: (string) ($incident->priority?->code ?? ''),
            assignedTo: $assignedTo,
            updatedAt: ($incident->updated_at ?? now())->toIso8601String(),
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
        return 'incidents.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'status' => $this->status,
            'priority' => $this->priority,
            'assigned_to' => $this->assignedTo,
            'updated_at' => $this->updatedAt,
        ];
    }
}
