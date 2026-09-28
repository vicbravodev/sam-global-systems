<?php

namespace App\Domains\Assets\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * Every position that moved forward in one telematics feed cycle, in one
 * socket message per tenant (chunked by the sender), instead of one queued
 * broadcast per vehicle per point.
 *
 * Sent now, not queued: it already runs inside the feed job, and a second hop
 * through the queue would only add latency to a live map.
 * Rescued: if Soketi is down the cycle still commits its cursor and ingest;
 * the map just misses one frame instead of the feed job failing.
 */
class FleetPositionsUpdatedBroadcast implements ShouldBroadcastNow, ShouldRescue
{
    /**
     * @param  list<array{asset_id: int, latitude: float, longitude: float, speed_kph: float|null, heading: int|null, recorded_at: string, moving: bool|null}>  $positions
     */
    public function __construct(
        public readonly int $teamId,
        public readonly array $positions,
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
        return 'fleet.positions_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'positions' => $this->positions,
        ];
    }
}
