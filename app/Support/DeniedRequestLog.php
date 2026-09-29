<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Throwable;

/**
 * Peticiones rechazadas que Laravel no reporta por defecto (401/403/419/429
 * y el 404 de un endpoint de webhook desconocido): son eventos de seguridad.
 * Sólo la plantilla de la ruta, nunca el path real (lleva ids de endpoint).
 *
 * Nunca rompe la respuesta: SystemLog traga cualquier fallo al escribir y el
 * único paso que puede fallar antes (resolver el usuario) tiene su propio guard.
 */
final class DeniedRequestLog
{
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

        if ($code === null) {
            return;
        }

        SystemLog::degraded($code, reason: $reason, input: [
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $request->route()?->uri(),
            'status' => $status,
            'exception' => $e::class,
        ] + self::actor($request));
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
