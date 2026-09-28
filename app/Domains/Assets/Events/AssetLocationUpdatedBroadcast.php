<?php

namespace App\Domains\Assets\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class AssetLocationUpdatedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

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

    /**
     * Operator-facing live updates: consumed by supervisor-high, not queued
     * behind analytics and audit on `default`.
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
