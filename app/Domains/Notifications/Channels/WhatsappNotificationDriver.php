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
 * Twilio Programmable Messaging — WhatsApp Business channel.
 *
 * Credentials are SAM's platform Twilio account (env TWILIO_*). The channel
 * `config_json` may only override non-secret values:
 *   - from        — Twilio WhatsApp sender (e.g. "whatsapp:+14155238886").
 *   - content_sid — Twilio pre-approved Content template SID. When set,
 *                   variables are forwarded as `content_variables`
 *                   (JSON-encoded) and the body is ignored. Without it, the
 *                   rendered body is sent as a free-form message, which
 *                   Twilio rejects outside the 24 h session window (63016,
 *                   permanent → fallback channel).
 *
 * Success means Twilio queued the message; delivery/read is confirmed later by
 * the status callback / reconciler.
 */
class WhatsappNotificationDriver implements NotificationDriver
{
    public function __construct(
        private readonly TwilioMessenger $messenger,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $config = PlatformTwilioConfig::resolve($channel->config_json ?? [], ChannelType::Whatsapp);
        $from = $config['from'];

        if ($from === null) {
            return TwilioDeliveryResults::misconfigured('whatsapp `from` missing', 'whatsapp');
        }

        if (! PlatformTwilioConfig::hasCredentials()) {
            return TwilioDeliveryResults::misconfigured('whatsapp twilio credentials missing', 'whatsapp');
        }

        $params = [
            'from' => $this->ensureWhatsappPrefix($from),
        ];

        if ($config['content_sid'] !== null) {
            $params['contentSid'] = $config['content_sid'];

            if ($notification->variables !== []) {
                $params['contentVariables'] = (string) json_encode($this->stringifyVariables($notification->variables));
            }
        } else {
            $params['body'] = $notification->body;
        }

        if (($callback = TwilioStatusCallbackUrl::resolve()) !== null) {
            $params['statusCallback'] = $callback;
        }

        $to = $this->ensureWhatsappPrefix($notification->address);

        try {
            $message = $this->messenger->createMessage($to, $params);
        } catch (\Throwable $e) {
            return TwilioDeliveryResults::fromException($e, 'whatsapp');
        }

        return TwilioDeliveryResults::accepted($message, MessagingResourceType::Message, 'whatsapp');
    }

    private function ensureWhatsappPrefix(string $value): string
    {
        return str_starts_with($value, 'whatsapp:') ? $value : 'whatsapp:'.$value;
    }

    /**
     * Twilio Content Variables expects string→string. Cast scalar values; drop arrays/objects.
     *
     * Claves numéricas ('1', '2'…) llegan como int en un array de PHP: de
     * ahí el `(string) $key`.
     *
     * @param  array<array-key, mixed>  $variables
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
