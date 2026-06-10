<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRead;
use App\Models\User;

class MarkNotificationRead
{
    /**
     * Mark the notification as read by the given user. Idempotent: repeated
     * calls keep the original read_at and never create duplicate rows.
     */
    public function execute(Notification $notification, User $user): NotificationRead
    {
        return NotificationRead::query()->firstOrCreate(
            [
                'notification_id' => $notification->id,
                'user_id' => $user->id,
            ],
            [
                'team_id' => $notification->team_id,
                'read_at' => now(),
            ],
        );
    }
}
