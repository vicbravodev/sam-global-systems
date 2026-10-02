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
use App\Domains\Notifications\Support\TwilioWebhookUrl;

/**
 * Twilio Voice driver (Roadmap V2-A3): delivers a notification as an outbound
 * phone call that reads the body aloud in Spanish (TTS). Interactive DTMF
 * verification calls are NOT this driver — those live in the Incidents domain
 * (`PlaceVerificationCallJob`); this driver covers escalation steps that
 * choose `voice` as a plain notification channel (Roadmap V2-A4).
 *
 * Credentials are SAM's platform Twilio account (env TWILIO_*). The channel
 * `config_json` may only override `from` (E.164) and `ring_timeout_seconds`.
 *
 * An incident notice gathers one digit: 1 acknowledges the incident
 * (TwilioNotificationCallController) and stops the escalation. The
 * notification counts as delivered when the call is answered (status callback
 * `in-progress`, or `completed` with duration). Success means Twilio queued
 * the call.
 */
class VoiceNotificationDriver implements NotificationDriver
{
    /**
     * Call progress events Twilio reports to the status callback.
     *
     * @var list<string>
     */
    public const STATUS_CALLBACK_EVENTS = ['initiated', 'ringing', 'answered', 'completed'];

    public function __construct(
        private readonly TwilioVoiceCaller $caller,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $config = PlatformTwilioConfig::resolve($channel->config_json ?? [], ChannelType::Voice);
        $from = $config['from'];

        if ($from === null) {
            return TwilioDeliveryResults::misconfigured('voice `from` missing', 'voice');
        }

        if (! PlatformTwilioConfig::hasCredentials()) {
            return TwilioDeliveryResults::misconfigured('voice twilio credentials missing', 'voice');
        }

        $params = [
            'twiml' => $this->twiml($notification),
            'timeout' => $config['ring_timeout_seconds'] ?? 25,
            // Detección de contestadora: un buzón no cuenta como "contestó"
            // (ApplyTwilioStatusUpdate lo marca fallido y cae a otro canal).
            'machineDetection' => 'Enable',
        ];

        if (($callback = TwilioStatusCallbackUrl::resolve()) !== null) {
            $params['statusCallback'] = $callback;
            $params['statusCallbackEvent'] = self::STATUS_CALLBACK_EVENTS;
        }

        try {
            $call = $this->caller->createCall($notification->address, $from, $params);
        } catch (\Throwable $e) {
            return TwilioDeliveryResults::fromException($e, 'voice');
        }

        return TwilioDeliveryResults::accepted($call, MessagingResourceType::Call, 'voice');
    }

    private function twiml(RenderedNotification $notification): string
    {
        $text = trim(($notification->subject !== null && $notification->subject !== '' ? $notification->subject.'. ' : '').$notification->body);
        $say = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $gatherUrl = $this->gatherUrl($notification);

        if ($gatherUrl === null) {
            // The message is read twice so a delayed pickup still hears it whole.
            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Response>'
                .'<Say language="es-MX">'.$say.'</Say>'
                .'<Pause length="1"/>'
                .'<Say language="es-MX">'.$say.'</Say>'
                .'</Response>';
        }

        // Aviso de un incidente: quien contesta puede atenderlo ahí mismo
        // (presiona 1) y la escalera se detiene. Se lee dos veces dentro del
        // Gather para que una tecla temprana corte la lectura.
        $action = htmlspecialchars($gatherUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $prompt = 'Para atender este incidente presiona 1.';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Gather numDigits="1" timeout="8" action="'.$action.'" method="POST">'
            .'<Say language="es-MX">'.$say.'</Say>'
            .'<Say language="es-MX">'.$prompt.'</Say>'
            .'<Pause length="1"/>'
            .'<Say language="es-MX">'.$say.'</Say>'
            .'<Say language="es-MX">'.$prompt.'</Say>'
            .'</Gather>'
            .'<Say language="es-MX">No recibimos respuesta. SAM seguirá avisando al equipo.</Say>'
            .'</Response>';
    }

    /**
     * URL del webhook que recibe la tecla, o null si la llamada no puede
     * atender un incidente o Twilio no alcanzaría la URL (local).
     */
    private function gatherUrl(RenderedNotification $notification): ?string
    {
        if (! $notification->acknowledgesIncident || $notification->deliveryId === null) {
            return null;
        }

        $url = TwilioWebhookUrl::route('webhooks.twilio.voice.notification.gather', ['delivery' => $notification->deliveryId]);

        return TwilioStatusCallbackUrl::isPublic($url) ? $url : null;
    }
}
