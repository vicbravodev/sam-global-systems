<?php

namespace App\Domains\Tenancy\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UsageUpdatedBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $teamId,
        public string $meterCode,
        public int $consumed,
        public int $included,
        public int $overage,
        public string $periodStart,
        public string $periodEnd,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'usage.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'meter_code' => $this->meterCode,
            'consumed' => $this->consumed,
            'included' => $this->included,
            'overage' => $this->overage,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
        ];
    }
}
