<?php

namespace App\Contracts\Notifications;

use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;

interface NotificationDriver
{
    /**
     * Deliver the rendered notification through the underlying provider.
     *
     * The channel carries provider-specific configuration (endpoints, secrets,
     * retry policy) in {@see NotificationChannel::$config_json}.
     */
    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult;
}
