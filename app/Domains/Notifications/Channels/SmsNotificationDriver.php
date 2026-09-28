<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Domains\Notifications\Support\TwilioStatusCallbackUrl;

/**
 * Twilio SMS driver. Body is truncated to 160 chars (155 + " (ver portal)")
 * so it always fits in a single segment.
 *
 * Credentials are SAM's platform Twilio account (env TWILIO_*). The channel
 * `config_json` may only override the sender (`from`: E.164 number or a
 * messaging service SID starting with "MG").
 *
 * Success means Twilio queued the message; delivery is confirmed later by the
 * status callback / reconciler.
 */
class SmsNotificationDriver implements NotificationDriver
{
    public const SUFFIX = '…(ver portal)';

    public const MAX_LENGTH = 160;

    public function __construct(
        private readonly TwilioMessenger $messenger,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $config = PlatformTwilioConfig::resolve($channel->config_json ?? [], ChannelType::Sms);
        $from = $config['from'];

        if ($from === null) {
            return TwilioDeliveryResults::misconfigured('sms `from` missing', 'sms');
        }

        if (! PlatformTwilioConfig::hasCredentials()) {
            return TwilioDeliveryResults::misconfigured('sms twilio credentials missing', 'sms');
        }

        $body = $this->truncate($notification->body);

        $params = [
            'body' => $body,
        ];

        // Twilio accepts either a phone number ("+...") or a messaging service SID ("MG...")
        if (str_starts_with($from, 'MG')) {
            $params['messagingServiceSid'] = $from;
        } else {
            $params['from'] = $from;
        }

        if (($callback = TwilioStatusCallbackUrl::resolve()) !== null) {
            $params['statusCallback'] = $callback;
        }

        try {
            $message = $this->messenger->createMessage($notification->address, $params);
        } catch (\Throwable $e) {
            return TwilioDeliveryResults::fromException($e, 'sms');
        }

        return TwilioDeliveryResults::accepted($message, MessagingResourceType::Message, 'sms', [
            'truncated_body_length' => mb_strlen($body),
        ]);
    }

    private function truncate(string $body): string
    {
        if (mb_strlen($body) <= self::MAX_LENGTH) {
            return $body;
        }

        $head = mb_substr($body, 0, self::MAX_LENGTH - mb_strlen(self::SUFFIX));

        return rtrim($head).self::SUFFIX;
    }
}
