<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;

class PushNotificationDriver implements NotificationDriver
{
    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        return DeliveryResult::failure('push_not_implemented');
    }
}
