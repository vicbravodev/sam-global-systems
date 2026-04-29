<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Actions\DispatchNotification;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Events\NotificationPushedBroadcast;
use Illuminate\Support\Str;

/**
 * Web channel driver - in-app notifications are broadcast on the user's
 * private channel via {@see NotificationPushedBroadcast}.
 * Broadcasting itself happens in {@see DispatchNotification}
 * after the delivery is recorded. This driver only acknowledges the queue handoff.
 */
class WebNotificationDriver implements NotificationDriver
{
    public function send(RenderedNotification $notification): DeliveryResult
    {
        return DeliveryResult::success(
            providerMessageId: 'web-'.(string) Str::uuid(),
            response: ['driver' => 'web', 'channel' => 'soketi'],
        );
    }
}
