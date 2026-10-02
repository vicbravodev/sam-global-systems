<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Support\Http\OutboundTarget;
use App\Support\Http\OutboundUrlGuard;
use App\Support\Http\UnsafeOutboundUrlException;
use App\Support\LoggableCode;
use App\Support\SystemLog;

/**
 * Protección SSRF de los drivers que hacen POST a una URL guardada en el
 * canal (Slack, webhook saliente). Misma política que `ExecuteAction` en
 * automatizaciones: {@see OutboundUrlGuard} rechaza la red interna (literal o
 * vía DNS) y devuelve las IPs validadas para fijarlas en la conexión, sin
 * redirecciones ({@see OutboundTarget::httpOptions()}).
 */
class OutboundChannelUrl
{
    public const ERROR_CODE = 'unsafe_url';

    public function __construct(
        private readonly OutboundUrlGuard $guard,
    ) {}

    /**
     * El destino validado o, si la URL no es segura, un fallo definitivo (no
     * se reintenta: la misma URL volvería a rechazarse) sin hacer la petición.
     */
    public function guard(string $url, NotificationChannel $channel, string $driver): OutboundTarget|DeliveryResult
    {
        try {
            return $this->guard->assertSafe($url);
        } catch (UnsafeOutboundUrlException $exception) {
            // El motivo lleva el host/IP detrás de ':' y no se registra.
            $unsafeUrlCode = LoggableCode::guard(explode(':', $exception->reason, 2)[0]);

            SystemLog::degraded('notifications.outbound_url.blocked', reason: self::ERROR_CODE, input: [
                'channel_id' => $channel->id,
                'channel_type' => $channel->channel_type->value,
                'driver' => $driver,
            ], calc: [
                'unsafe_url_code' => $unsafeUrlCode,
            ], result: [
                'request_sent' => false,
                'permanent' => true,
            ]);

            return DeliveryResult::failure(
                "{$driver} url not allowed (".($unsafeUrlCode ?? 'unsafe_url').')',
                ['driver' => $driver, 'unsafe_url_code' => $unsafeUrlCode],
                permanent: true,
                providerErrorCode: self::ERROR_CODE,
            );
        }
    }

    /**
     * Respuesta 3xx: no se siguen redirecciones (podrían llevar a la red
     * interna sin pasar por el guard). Definitivo: es un problema de la URL.
     *
     * @param  array<string, mixed>  $responsePayload
     */
    public static function redirectFailure(string $driver, int $status, array $responsePayload): DeliveryResult
    {
        return DeliveryResult::failure(
            "{$driver} returned a redirect (HTTP {$status}); redirects are not followed",
            $responsePayload,
            permanent: true,
            providerErrorCode: 'redirect_not_followed',
        );
    }
}
