<?php

namespace App\Domains\Assets\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class AssetMonitoringChangedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly string $name,
        public readonly string $previousState,
        public readonly string $newState,
    ) {}

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
        return 'asset.monitoring_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'asset_id' => $this->assetId,
            'name' => $this->name,
            'previous_state' => $this->previousState,
            'new_state' => $this->newState,
        ];
    }
}
