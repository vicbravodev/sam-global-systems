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
 * acknowledges, dismisses or escalates the incident. Every sender number is
 * SAM's platform account (env TWILIO_*), whose auth token validates
 * `X-Twilio-Signature`; the reply token identifies the tenant.
 */
class TwilioInboundController extends Controller
{
    public function handle(Request $request, ProcessInboundReply $processInboundReply): Response
    {
        if (! $this->isPlatformNumber((string) $request->input('To', ''))) {
            abort(403, 'Unknown Twilio number.');
        }

        $authToken = PlatformTwilioConfig::authToken();

        if ($authToken === null) {
            abort(403, 'Twilio is not configured.');
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
