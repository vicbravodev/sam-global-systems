<?php

namespace App\Domains\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationDelivered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $notificationId,
        public readonly int $deliveryId,
        public readonly string $channelType,
    ) {}
}
