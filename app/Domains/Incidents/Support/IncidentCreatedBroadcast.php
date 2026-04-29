<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Models\Incident;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class IncidentCreatedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $incidentId,
        public readonly string $title,
        public readonly string $priority,
        public readonly string $status,
        public readonly ?int $assetId,
        public readonly ?int $driverId,
        public readonly string $openedAt,
    ) {}

    public static function fromModel(Incident $incident): self
    {
        $incident->loadMissing(['priority', 'status']);

        return new self(
            teamId: (int) $incident->team_id,
            incidentId: (int) $incident->id,
            title: (string) $incident->title,
            priority: (string) ($incident->priority?->code ?? ''),
            status: (string) ($incident->status?->code ?? ''),
            assetId: $incident->asset_id !== null ? (int) $incident->asset_id : null,
            driverId: $incident->driver_id !== null ? (int) $incident->driver_id : null,
            openedAt: $incident->opened_at?->toIso8601String() ?? now()->toIso8601String(),
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
        return 'incidents.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'incident_id' => $this->incidentId,
            'title' => $this->title,
            'priority' => $this->priority,
            'status' => $this->status,
            'asset_id' => $this->assetId,
            'driver_id' => $this->driverId,
            'opened_at' => $this->openedAt,
        ];
    }
}
