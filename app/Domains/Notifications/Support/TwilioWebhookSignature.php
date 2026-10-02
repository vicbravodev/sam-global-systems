<?php

namespace App\Domains\Notifications\Support;

use App\Support\SystemLog;
use Illuminate\Http\Request;
use Twilio\Security\RequestValidator;

/**
 * Valida `X-Twilio-Signature` de un webhook con el auth token de la cuenta de
 * plataforma (todas las llamadas y mensajes salen de ella). Un rechazo queda
 * en `webhook.twilio.signature_rejected` con su motivo y corta con 403; nunca
 * se registran la firma, el token, la URL firmada ni los parámetros.
 */
final class TwilioWebhookSignature
{
    /**
     * @param  string  $endpoint  `inbound` | `status_callback` | `notification_call` | `call_verification`
     * @param  string|null  $signedUrl  URL que firmó Twilio si no es la de la petición
     */
    public static function verify(Request $request, string $endpoint, ?string $signedUrl = null): void
    {
        $authToken = PlatformTwilioConfig::authToken();

        if ($authToken === null) {
            self::reject('not_configured', $request, $endpoint, $signedUrl);

            abort(403, 'Twilio is not configured.');
        }

        $signature = $request->header('X-Twilio-Signature', '');

        if ($signature === '') {
            self::reject('empty_signature', $request, $endpoint, $signedUrl);

            abort(403, 'Invalid Twilio signature.');
        }

        $isValid = (new RequestValidator($authToken))->validate(
            $signature,
            $signedUrl ?? TwilioWebhookUrl::forSignature($request),
            TwilioWebhookUrl::signedParams($request),
        );

        if (! $isValid) {
            self::reject('hmac_mismatch', $request, $endpoint, $signedUrl);

            abort(403, 'Invalid Twilio signature.');
        }
    }

    private static function reject(string $reason, Request $request, string $endpoint, ?string $signedUrl): void
    {
        $base = config('services.twilio.public_base_url');

        SystemLog::degraded('webhook.twilio.signature_rejected', reason: $reason, input: [
            'endpoint' => $endpoint,
            'route_name' => $request->route()?->getName(),
        ], calc: [
            // De dónde salió la URL validada: una base pública mal fijada
            // detrás de un proxy es la causa típica de `hmac_mismatch`.
            'signed_url_source' => match (true) {
                $signedUrl !== null => 'configured_callback_url',
                is_string($base) && trim($base) !== '' => 'public_base_url',
                default => 'request_url',
            },
            'signed_params_count' => count(TwilioWebhookUrl::signedParams($request)),
        ]);
    }
}
