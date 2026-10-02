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
 *   - content_sid — Twilio pre-approved Content template SID (SAM's generic
 *                   alert template: {{1}} subject, {{2}} body). When set, the
 *                   rendered subject/body go as positional
 *                   `contentVariables`, reply token included. Without it, the
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

            // Plantilla genérica de SAM con dos variables posicionales:
            // {{1}} = asunto, {{2}} = cuerpo ya renderizado (con el token de
            // respuesta SI-/NO-/ESC-). Antes se mandaba el payload crudo
            // ({"incident_id":…}), que no casa con {{1}}/{{2}}, y el token se
            // perdía: nadie podía atender respondiendo por WhatsApp.
            $params['contentVariables'] = (string) json_encode([
                '1' => $this->templateParam($notification->subject ?? 'Aviso de SAM'),
                '2' => $this->templateParam($notification->body),
            ], JSON_UNESCAPED_UNICODE);
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
     * WhatsApp rechaza variables de plantilla con saltos de línea, tabuladores
     * o más de 4 espacios seguidos, y de más de 1024 caracteres.
     */
    private function templateParam(string $value): string
    {
        $flat = (string) preg_replace('/\s*\R\s*/u', ' · ', trim($value));
        $flat = (string) preg_replace('/[\t ]{2,}/', ' ', $flat);

        return mb_substr($flat, 0, 1024);
    }
}
