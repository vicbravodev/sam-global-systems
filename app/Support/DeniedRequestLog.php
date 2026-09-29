<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Peticiones rechazadas que Laravel no reporta por defecto (401/403/419/429
 * y el 404 de un endpoint de webhook desconocido): son eventos de seguridad.
 * Sólo la plantilla de la ruta, nunca el path real (lleva ids de endpoint).
 *
 * Nunca rompe la respuesta: cualquier fallo se traga, salvo la violación de
 * esquema de SystemLog en tests.
 */
final class DeniedRequestLog
{
    public static function record(Throwable $e, Request $request, int $status): void
    {
        try {
            self::write($e, $request, $status);
        } catch (Throwable $failure) {
            if ($failure instanceof InvalidArgumentException && app()->runningUnitTests()) {
                throw $failure;
            }
            // Se traga sin registrar: el log es justo lo que falló.
        }
    }

    private static function write(Throwable $e, Request $request, int $status): void
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

        if ($code === null) {
            return;
        }

        $user = $request->user();

        SystemLog::degraded($code, reason: $reason, input: [
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $request->route()?->uri(),
            'status' => $status,
            'exception' => $e::class,
            'user_id' => $user?->getAuthIdentifier(),
            'team_id' => TenantContext::id() ?? $user?->current_team_id,
        ]);
    }
}
