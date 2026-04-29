<?php

namespace App\Contracts\Notifications;

use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;

interface NotificationDriver
{
    /**
     * Deliver the rendered notification through the underlying provider.
     */
    public function send(RenderedNotification $notification): DeliveryResult;
}
