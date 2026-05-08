<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;
use Twilio\Exceptions\TwilioException;

/**
 * Twilio Programmable Messaging — WhatsApp Business channel.
 *
 * Required config_json keys:
 *   - twilio_account_sid (or account_sid) — cifrado at rest.
 *   - twilio_auth_token  (or auth_token)  — cifrado at rest.
 *   - from               — Twilio WhatsApp sender (e.g. "whatsapp:+14155238886").
 *
 * Optional:
 *   - content_sid / template_sid — Twilio pre-approved Content template SID.
 *                                  When set, variables are forwarded as
 *                                  `content_variables` (JSON-encoded) and the
 *                                  body is ignored. Without it, the rendered
 *                                  body is sent as a plain message.
 */
class WhatsappNotificationDriver implements NotificationDriver
{
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
            return DeliveryResult::failure('whatsapp `from` missing');
        }

        if (! is_string($sid) || $sid === '' || ! is_string($token) || $token === '') {
            return DeliveryResult::failure('whatsapp twilio credentials missing');
        }

        $contentSid = $config['content_sid'] ?? $config['template_sid'] ?? null;

        $params = [
            'from' => $this->ensureWhatsappPrefix($from),
        ];

        if (is_string($contentSid) && $contentSid !== '') {
            $params['contentSid'] = $contentSid;

            if ($notification->variables !== []) {
                $params['contentVariables'] = (string) json_encode($this->stringifyVariables($notification->variables));
            }
        } else {
            $params['body'] = $notification->body;
        }

        $to = $this->ensureWhatsappPrefix($notification->address);

        try {
            $message = $this->messenger->createMessage($config, $to, $params);
        } catch (TwilioException $e) {
            return DeliveryResult::failure('twilio whatsapp error: '.$e->getMessage(), [
                'driver' => 'whatsapp',
                'twilio_code' => $e->getCode(),
            ]);
        } catch (\Throwable $e) {
            return DeliveryResult::failure('whatsapp error: '.$e->getMessage(), [
                'driver' => 'whatsapp',
            ]);
        }

        return DeliveryResult::success(
            providerMessageId: (string) ($message->sid ?? ''),
            response: [
                'driver' => 'whatsapp',
                'status' => (string) ($message->status ?? ''),
                'sid' => (string) ($message->sid ?? ''),
            ],
        );
    }

    private function ensureWhatsappPrefix(string $value): string
    {
        return str_starts_with($value, 'whatsapp:') ? $value : 'whatsapp:'.$value;
    }

    /**
     * Twilio Content Variables expects string→string. Cast scalar values; drop arrays/objects.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, string>
     */
    private function stringifyVariables(array $variables): array
    {
        $out = [];
        foreach ($variables as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
