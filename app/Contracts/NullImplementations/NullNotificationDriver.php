<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use Illuminate\Support\Str;

/**
 * SPEC-13-CHANNEL-DEFERRED: a deterministic stub used for SMS/Push/Whatsapp/Slack/Webhook
 * drivers until provider integrations land in PR #2. Always returns success with a
 * synthetic provider_message_id so the rest of the delivery pipeline stays exercised.
 */
class NullNotificationDriver implements NotificationDriver
{
    public function send(RenderedNotification $notification): DeliveryResult
    {
        return DeliveryResult::success(
            providerMessageId: 'null-'.$notification->channelType->value.'-'.(string) Str::uuid(),
            response: [
                'driver' => 'null',
                'channel_type' => $notification->channelType->value,
                'note' => 'SPEC-13-CHANNEL-DEFERRED',
            ],
        );
    }
}
