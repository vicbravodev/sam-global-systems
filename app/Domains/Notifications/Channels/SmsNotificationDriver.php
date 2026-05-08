<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;
use Twilio\Exceptions\TwilioException;

/**
 * Twilio SMS driver. Body is truncated to 160 chars (155 + " (ver portal)")
 * so it always fits in a single segment.
 *
 * Required config_json keys:
 *   - twilio_account_sid (or account_sid) — cifrado at rest.
 *   - twilio_auth_token  (or auth_token)  — cifrado at rest.
 *   - from               — Twilio sender (E.164, e.g. "+14155238886") or
 *                          messaging service SID (starts with "MG").
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
        $config = $channel->config_json ?? [];

        $from = $config['from'] ?? null;
        $sid = $config['twilio_account_sid'] ?? $config['account_sid'] ?? null;
        $token = $config['twilio_auth_token'] ?? $config['auth_token'] ?? null;

        if (! is_string($from) || $from === '') {
            return DeliveryResult::failure('sms `from` missing');
        }

        if (! is_string($sid) || $sid === '' || ! is_string($token) || $token === '') {
            return DeliveryResult::failure('sms twilio credentials missing');
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

        try {
            $message = $this->messenger->createMessage($config, $notification->address, $params);
        } catch (TwilioException $e) {
            return DeliveryResult::failure('twilio sms error: '.$e->getMessage(), [
                'driver' => 'sms',
                'twilio_code' => $e->getCode(),
            ]);
        } catch (\Throwable $e) {
            return DeliveryResult::failure('sms error: '.$e->getMessage(), [
                'driver' => 'sms',
            ]);
        }

        return DeliveryResult::success(
            providerMessageId: (string) ($message->sid ?? ''),
            response: [
                'driver' => 'sms',
                'status' => (string) ($message->status ?? ''),
                'sid' => (string) ($message->sid ?? ''),
                'truncated_body_length' => mb_strlen($body),
            ],
        );
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
