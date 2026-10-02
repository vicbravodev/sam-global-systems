<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Notifications\Actions\ProcessInboundReply;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Support\MessagingSuppressions;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Domains\Notifications\Support\TwilioWebhookSignature;
use App\Http\Controllers\Controller;
use App\Support\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Inbound Twilio webhook (Roadmap B9): the operator answers the critical
 * incident SMS/WhatsApp ("SI-4F2A" / "NO-4F2A" / "ESC-4F2A") and SAM
 * acknowledges, dismisses or escalates the incident. Every sender number is
 * SAM's platform account (env TWILIO_*), whose auth token validates
 * `X-Twilio-Signature`; the reply token identifies the tenant.
 */
class TwilioInboundController extends Controller
{
    public function handle(Request $request, ProcessInboundReply $processInboundReply): Response
    {
        $to = (string) $request->input('To', '');

        if (! $this->isPlatformNumber($to)) {
            // Nunca el número: sólo si venía y su canal (sms/whatsapp).
            SystemLog::skipped('webhook.twilio.unknown_number', reason: 'not_platform_sender', calc: [
                'to_present' => trim($to) !== '',
                'to_channel' => str_starts_with(strtolower(trim($to)), 'whatsapp:') ? 'whatsapp' : 'sms',
            ]);

            abort(403, 'Unknown Twilio number.');
        }

        TwilioWebhookSignature::verify($request, 'inbound');

        $from = (string) $request->input('From', '');
        $body = (string) $request->input('Body', '');

        $optOut = $this->handleOptKeyword($from, $body);

        if ($optOut !== false) {
            return response($this->twiml($optOut), 200)->header('Content-Type', 'text/xml');
        }

        $reply = $processInboundReply->execute(
            fromAddress: $from,
            body: $body,
        );

        return response($this->twiml($reply), 200)->header('Content-Type', 'text/xml');
    }

    /**
     * The `To` of an inbound message must be one of SAM's Twilio senders: the
     * effective `from` (channel override or platform env) of an active
     * platform SMS/WhatsApp channel. config_json is encrypted at rest, so the
     * match happens in PHP, not SQL.
     */
    private function isPlatformNumber(string $to): bool
    {
        $normalized = $this->normalize($to);

        if ($normalized === '') {
            return false;
        }

        return NotificationChannel::query()
            ->where('provider', 'twilio')
            ->where('is_active', true)
            ->whereIn('channel_type', [ChannelType::Sms, ChannelType::Whatsapp])
            ->get()
            ->contains(function (NotificationChannel $channel) use ($normalized): bool {
                $from = PlatformTwilioConfig::resolve($channel->config_json ?? [], $channel->channel_type)['from'];

                return $from !== null && $this->normalize($from) === $normalized;
            });
    }

    /**
     * STOP/BAJA dan de baja al remitente para ese canal; START/ALTA lo
     * levantan. Devuelve false si el mensaje no es una de esas palabras (sigue
     * el flujo de respuestas), o el texto a contestar (null = sin respuesta:
     * Twilio ya contesta solo las palabras estándar en inglés).
     */
    private function handleOptKeyword(string $from, string $body): string|false|null
    {
        $keyword = strtoupper(trim($body));
        $channel = str_starts_with(strtolower(trim($from)), 'whatsapp:') ? ChannelType::Whatsapp : ChannelType::Sms;

        if ($from === '') {
            return false;
        }

        if (in_array($keyword, MessagingSuppressions::OPT_OUT_KEYWORDS, true)) {
            MessagingSuppressions::suppress($channel, $from, 'opted_out', 'inbound_keyword');

            return $keyword === 'BAJA'
                ? 'Listo: ya no recibirás avisos de SAM por este medio. Responde ALTA para volver a recibirlos.'
                : null;
        }

        if (in_array($keyword, MessagingSuppressions::OPT_IN_KEYWORDS, true)) {
            MessagingSuppressions::lift($channel, $from);

            return $keyword === 'ALTA' ? 'Listo: volverás a recibir avisos de SAM por este medio.' : null;
        }

        return false;
    }

    private function normalize(string $address): string
    {
        return (string) preg_replace('/^whatsapp:/i', '', trim($address));
    }

    private function twiml(?string $message): string
    {
        if ($message === null) {
            return '<?xml version="1.0" encoding="UTF-8"?><Response/>';
        }

        $escaped = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?><Response><Message>'.$escaped.'</Message></Response>';
    }
}
