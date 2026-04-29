<?php

namespace App\Domains\Notifications\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class NotificationPushedBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $notificationId,
        public readonly string $notificationType,
        public readonly string $priority,
        public readonly ?string $subject = null,
        public readonly ?string $bodyPreview = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("users.{$this->userId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.pushed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'notification_id' => $this->notificationId,
            'notification_type' => $this->notificationType,
            'priority' => $this->priority,
            'subject' => $this->subject,
            'body_preview' => $this->bodyPreview,
        ];
    }
}
