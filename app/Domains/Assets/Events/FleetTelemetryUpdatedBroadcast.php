<?php

namespace App\Domains\Assets\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * The diagnostics that changed in one telematics feed cycle (ignition, fuel,
 * odometer, battery, temperature), one socket message per tenant.
 */
class FleetTelemetryUpdatedBroadcast implements ShouldBroadcastNow, ShouldRescue
{
    /**
     * @param  list<array{asset_id: int, readings: array<string, array{value: float|string, unit: string|null, recorded_at: string}>}>  $assets
     */
    public function __construct(
        public readonly int $teamId,
        public readonly array $assets,
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
        return 'fleet.telemetry_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'assets' => $this->assets,
        ];
    }
}
