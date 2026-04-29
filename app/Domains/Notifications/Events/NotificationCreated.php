<?php

namespace App\Domains\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $notificationId,
        public readonly string $notificationType,
        public readonly int $recipientCount,
    ) {}
}
