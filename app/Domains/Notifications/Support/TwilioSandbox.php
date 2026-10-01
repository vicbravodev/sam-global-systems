<?php

namespace App\Domains\Notifications\Support;

/**
 * Twilio simulado para desarrollo (`TWILIO_SANDBOX=true`). Permite ver el
 * ciclo completo de feedback (aceptado → entregado/fallido, precio,
 * facturación) en el UI local sin gastar ni tener cuenta Twilio.
 *
 * Resultado determinista por número destino: el último dígito del número
 * viaja codificado al final del SID simulado y decide el estado terminal
 * que devuelve el "fetch" del reconciliador:
 *
 *   - Mensaje a número terminado en 0 → `undelivered` (30003), con costo.
 *   - Llamada a número terminado en 1 → `no-answer`, sin costo.
 *   - Resto → mensaje `delivered` / llamada `completed` de 42 s.
 *
 * Nunca activo en producción, aunque la variable esté puesta.
 */
final class TwilioSandbox
{
    public const SMS_SEGMENT_PRICE = '-0.00790';

    public const WHATSAPP_PRICE = '-0.00500';

    public const CALL_PRICE = '-0.01400';

    public const CALL_DURATION = 42;

    public static function enabled(): bool
    {
        return (bool) config('services.twilio.sandbox', false) && ! app()->isProduction();
    }

    /**
     * @param  array<string, mixed>  $params  Twilio `messages->create` params (`from`, `body`, `contentSid`, `statusCallback`, ...).
     */
    public static function createMessage(string $to, array $params): object
    {
        $body = (string) ($params['body'] ?? '');

        return (object) [
            'sid' => self::sid('SM', $to),
            'status' => 'queued',
            'numSegments' => (string) max(1, (int) ceil(mb_strlen($body) / 153)),
            'price' => null,
            'priceUnit' => 'USD',
            'errorCode' => null,
        ];
    }

    public static function createCall(string $to): object
    {
        return (object) [
            'sid' => self::sid('CA', $to),
            'status' => 'queued',
            'duration' => null,
            'price' => null,
            'priceUnit' => 'USD',
        ];
    }

    public static function fetchMessage(string $sid): object
    {
        $isWhatsapp = ($sid[2] ?? '') === 'w';
        $failed = str_ends_with($sid, '0');

        return (object) [
            'sid' => $sid,
            'status' => $failed ? 'undelivered' : 'delivered',
            'errorCode' => $failed ? 30003 : null,
            'numSegments' => '1',
            'price' => $isWhatsapp ? self::WHATSAPP_PRICE : self::SMS_SEGMENT_PRICE,
            'priceUnit' => 'USD',
        ];
    }

    public static function fetchCall(string $sid): object
    {
        $unanswered = str_ends_with($sid, '1');

        return (object) [
            'sid' => $sid,
            'status' => $unanswered ? 'no-answer' : 'completed',
            'duration' => $unanswered ? '0' : (string) self::CALL_DURATION,
            'price' => $unanswered ? null : self::CALL_PRICE,
            'priceUnit' => 'USD',
        ];
    }

    /**
     * SID de 34 caracteres como los reales: prefijo + 31 hex + el último
     * dígito del destino. Los WhatsApp llevan una `w` en la posición 2 para
     * que el fetch simulado devuelva la tarifa de WhatsApp.
     */
    private static function sid(string $prefix, string $to): string
    {
        $digits = preg_replace('/\D/', '', $to) ?: '9';
        $marker = str_starts_with($to, 'whatsapp:') ? 'w' : 'f';
        $random = substr(bin2hex(random_bytes(16)), 0, 30);

        return $prefix.$marker.$random.substr($digits, -1);
    }
}
