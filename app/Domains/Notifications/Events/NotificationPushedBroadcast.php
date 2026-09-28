<?php

namespace App\Domains\Notifications\Events;

use App\Support\Broadcasting\QueuesRealtimeBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Queue\SerializesModels;

class NotificationPushedBroadcast implements ShouldBroadcast, ShouldRescue
{
    use QueuesRealtimeBroadcast, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $notificationId,
        public readonly string $notificationType,
        public readonly string $priority,
        public readonly ?string $subject = null,
        public readonly ?string $bodyPreview = null,
        // A user can belong to several teams; the client only raises the
        // toast when this matches the team it is looking at.
        public readonly ?int $teamId = null,
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
            'team_id' => $this->teamId,
        ];
    }
}
