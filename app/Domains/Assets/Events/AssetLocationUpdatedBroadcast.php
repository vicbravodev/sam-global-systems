<?php

namespace App\Domains\Assets\Events;

use App\Support\Broadcasting\QueuesRealtimeBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Queue\SerializesModels;

class AssetLocationUpdatedBroadcast implements ShouldBroadcast, ShouldRescue
{
    use QueuesRealtimeBroadcast, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $assetId,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly string $recordedAt,
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
        return 'asset.location_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'asset_id' => $this->assetId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
