<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Notifications\Actions\ProcessInboundReply;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\Security\RequestValidator;

/**
 * Inbound Twilio webhook (Roadmap B9): the operator answers the critical
 * incident SMS/WhatsApp ("SI-4F2A" / "NO-4F2A" / "ESC-4F2A") and SAM
 * acknowledges, dismisses or escalates the incident. The receiving Twilio
 * number (the `To` param) identifies the tenant channel whose auth token
 * validates `X-Twilio-Signature`.
 */
class TwilioInboundController extends Controller
{
    public function handle(Request $request, ProcessInboundReply $processInboundReply): Response
    {
        $channel = $this->resolveChannel((string) $request->input('To', ''));

        if ($channel === null) {
            abort(403, 'Unknown Twilio number.');
        }

        // Igual que los drivers salientes: las credenciales de plataforma
        // (TWILIO_*) rellenan lo que el config_json del canal no trae.
        $config = $this->effectiveConfig($channel);
        $authToken = $config['twilio_auth_token'] ?? $config['auth_token'] ?? null;

        if (! is_string($authToken) || $authToken === '') {
            abort(403, 'Twilio channel has no auth token configured.');
        }

        $validator = new RequestValidator($authToken);

        $isValid = $validator->validate(
            (string) $request->header('X-Twilio-Signature', ''),
            $request->fullUrl(),
            $request->post(),
        );

        if (! $isValid) {
            abort(403, 'Invalid Twilio signature.');
        }

        $reply = $processInboundReply->execute(
            fromAddress: (string) $request->input('From', ''),
            body: (string) $request->input('Body', ''),
            channelTeamId: $channel->team_id !== null ? (int) $channel->team_id : null,
        );

        return response($this->twiml($reply), 200)->header('Content-Type', 'text/xml');
    }

    /**
     * The `To` of an inbound message is one of our Twilio senders — match it
     * against the configured `from` of active twilio channels. config_json is
     * encrypted at rest, so the match happens in PHP, not SQL.
     *
     * Primero gana un canal con `from` propio en su config_json (override
     * explícito). Si ninguno, el número es de plataforma: se compara contra
     * los canales de plataforma (team_id null) con la config efectiva de
     * PlatformTwilioConfig — sus credenciales viven en TWILIO_*, no en la
     * fila. Un canal de tenant sin `from` propio comparte el número de
     * plataforma y no debe capturar las respuestas de todos los tenants.
     */
    private function resolveChannel(string $to): ?NotificationChannel
    {
        $normalized = $this->normalize($to);

        if ($normalized === '') {
            return null;
        }

        $channels = NotificationChannel::query()
            ->where('provider', 'twilio')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $matches = fn (array $config): bool => ($from = (string) ($config['from'] ?? '')) !== ''
            && $this->normalize($from) === $normalized;

        return $channels->first(fn (NotificationChannel $channel): bool => $matches($channel->config_json ?? []))
            ?? $channels
                ->filter(fn (NotificationChannel $channel): bool => $channel->team_id === null)
                ->first(fn (NotificationChannel $channel): bool => $matches($this->effectiveConfig($channel)));
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveConfig(NotificationChannel $channel): array
    {
        return PlatformTwilioConfig::merge(
            $channel->config_json ?? [],
            $channel->channel_type ?? ChannelType::Sms,
        );
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
