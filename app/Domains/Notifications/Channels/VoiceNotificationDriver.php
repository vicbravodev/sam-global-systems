<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;
use Twilio\Exceptions\TwilioException;

/**
 * Twilio Voice driver (Roadmap V2-A3): delivers a notification as an outbound
 * phone call that reads the body aloud in Spanish (TTS). Interactive DTMF
 * verification calls are NOT this driver — those live in the Incidents domain
 * (`PlaceVerificationCallJob`); this driver covers escalation steps that
 * choose `voice` as a plain notification channel (Roadmap V2-A4).
 *
 * Required config_json keys:
 *   - twilio_account_sid (or account_sid) — cifrado at rest.
 *   - twilio_auth_token  (or auth_token)  — cifrado at rest.
 *   - from               — Twilio voice number (E.164).
 */
class VoiceNotificationDriver implements NotificationDriver
{
    public function __construct(
        private readonly TwilioVoiceCaller $caller,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $config = $channel->config_json ?? [];

        $from = $config['from'] ?? null;
        $sid = $config['twilio_account_sid'] ?? $config['account_sid'] ?? null;
        $token = $config['twilio_auth_token'] ?? $config['auth_token'] ?? null;

        if (! is_string($from) || $from === '') {
            return DeliveryResult::failure('voice `from` missing');
        }

        if (! is_string($sid) || $sid === '' || ! is_string($token) || $token === '') {
            return DeliveryResult::failure('voice twilio credentials missing');
        }

        try {
            $call = $this->caller->createCall($config, $notification->address, $from, [
                'twiml' => $this->twiml($notification),
                'timeout' => (int) ($config['ring_timeout_seconds'] ?? 25),
            ]);
        } catch (TwilioException $e) {
            return DeliveryResult::failure('twilio voice error: '.$e->getMessage(), [
                'driver' => 'voice',
                'twilio_code' => $e->getCode(),
            ]);
        } catch (\Throwable $e) {
            return DeliveryResult::failure('voice error: '.$e->getMessage(), [
                'driver' => 'voice',
            ]);
        }

        return DeliveryResult::success(
            providerMessageId: (string) ($call->sid ?? ''),
            response: [
                'driver' => 'voice',
                'status' => (string) ($call->status ?? ''),
                'sid' => (string) ($call->sid ?? ''),
            ],
        );
    }

    private function twiml(RenderedNotification $notification): string
    {
        $text = trim(($notification->subject !== null && $notification->subject !== '' ? $notification->subject.'. ' : '').$notification->body);
        $say = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        // The message is read twice so a delayed pickup still hears it whole.
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Say language="es-MX">'.$say.'</Say>'
            .'<Pause length="1"/>'
            .'<Say language="es-MX">'.$say.'</Say>'
            .'</Response>';
    }
}
