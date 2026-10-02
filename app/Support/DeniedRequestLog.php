<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Peticiones rechazadas que Laravel no reporta por defecto (401/403/419/429
 * y el 404 de un endpoint de webhook desconocido): son eventos de seguridad.
 * Sólo la plantilla de la ruta, nunca el path real (lleva ids de endpoint).
 *
 * Nunca rompe la respuesta: SystemLog traga cualquier fallo al escribir y el
 * único paso que puede fallar antes (resolver el usuario) tiene su propio guard.
 *
 * Límite de volumen: un bot que martilla una ruta (o un 429 en bucle) no debe
 * inundar el log. Por cubeta (código + motivo + ruta + actor) se escriben las
 * primeras `MAX_PER_WINDOW` líneas de cada ventana de `WINDOW_SECONDS`; el
 * resto se cuenta y la primera línea de la ventana siguiente lo reporta en
 * `calc.suppressed_since_last`. Si la caché falla, se escribe siempre.
 */
final class DeniedRequestLog
{
    public const int MAX_PER_WINDOW = 20;

    public const int WINDOW_SECONDS = 60;

    public static function record(Throwable $e, Request $request, int $status): void
    {
        if ($e instanceof AuthenticationException) {
            $status = 401;
        }

        [$code, $reason] = match (true) {
            $status === 401 => ['http.request.denied', 'unauthenticated'],
            $status === 403 => ['http.request.denied', 'forbidden'],
            $status === 419 => ['http.request.denied', 'csrf_mismatch'],
            $status === 429 => ['http.request.throttled', 'rate_limited'],
            $status === 404 && $request->is('api/webhooks/*') => ['http.request.not_found', 'unknown_endpoint'],
            default => [null, null],
        };

        if ($code === null || $reason === null) {
            return;
        }

        $actor = self::actor($request);
        $routeUri = $request->route()?->uri();

        $suppressed = self::admit($code, $reason, $routeUri, $actor['user_id']);

        if ($suppressed === null) {
            return;
        }

        SystemLog::degraded($code, reason: $reason, input: [
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $routeUri,
            'status' => $status,
            'exception' => $e::class,
        ] + $actor, calc: $suppressed > 0 ? [
            'suppressed_since_last' => $suppressed,
            'max_per_window' => self::MAX_PER_WINDOW,
            'window_seconds' => self::WINDOW_SECONDS,
        ] : null);
    }

    /**
     * null = la cubeta ya escribió su cupo en esta ventana (se cuenta y se
     * calla); un entero = se escribe, con las suprimidas desde la última línea.
     */
    private static function admit(string $code, string $reason, ?string $routeUri, mixed $userId): ?int
    {
        try {
            $bucket = 'denied-log:'.sha1(implode('|', [$code, $reason, $routeUri ?? '-', is_scalar($userId) ? (string) $userId : 'guest']));

            if (RateLimiter::tooManyAttempts($bucket, self::MAX_PER_WINDOW)) {
                // Con TTL: si la ráfaga cesa y nadie vuelve a escribir, el
                // contador no queda huérfano para siempre.
                Cache::add($bucket.':suppressed', 0, self::WINDOW_SECONDS * 60);
                Cache::increment($bucket.':suppressed');

                return null;
            }

            RateLimiter::hit($bucket, self::WINDOW_SECONDS);

            $suppressed = Cache::pull($bucket.':suppressed', 0);

            return is_numeric($suppressed) ? (int) $suppressed : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Resolver el usuario puede tocar la DB o la sesión: si falla, la línea
     * sale sin actor en vez de romper la respuesta.
     *
     * @return array{user_id: mixed, team_id: mixed}
     */
    private static function actor(Request $request): array
    {
        try {
            $user = $request->user();

            return ['user_id' => $user?->getAuthIdentifier(), 'team_id' => TenantContext::id() ?? $user?->current_team_id];
        } catch (Throwable) {
            return ['user_id' => null, 'team_id' => TenantContext::id()];
        }
    }
}
