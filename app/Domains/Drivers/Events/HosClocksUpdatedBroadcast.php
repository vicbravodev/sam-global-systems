<?php

namespace App\Domains\Drivers\Events;

use App\Support\Broadcasting\QueuesRealtimeBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Queue\SerializesModels;

/**
 * Un sondeo HOS del tenant terminó: el panel del chofer y la vista de flota
 * recargan sus props. Payload mínimo (sin choferes ni relojes): la página
 * pide lo suyo con su propia autorización.
 */
class HosClocksUpdatedBroadcast implements ShouldBroadcast, ShouldRescue
{
    use QueuesRealtimeBroadcast, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $monitored,
        public readonly string $observedAt,
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
        return 'hos.clocks_updated';
    }

    /**
     * @return array{monitored: int, observed_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'monitored' => $this->monitored,
            'observed_at' => $this->observedAt,
        ];
    }
}
