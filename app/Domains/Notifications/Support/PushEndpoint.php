<?php

namespace App\Domains\Notifications\Support;

/**
 * Qué suscripción de avisos al dispositivo aceptamos. El endpoint lo manda el
 * navegador y el servidor le hace POST: sólo se admiten los servicios de push
 * conocidos (`webpush.allowed_hosts`), si no sería una puerta a SSRF. Lo usan
 * el alta (StorePushSubscriptionRequest) y el envío (WebPushMessenger), así
 * las filas guardadas antes de esta regla tampoco salen hacia otro host.
 */
final class PushEndpoint
{
    /**
     * https, sin usuario/contraseña, sin puerto distinto de 443 y con un host
     * de la lista. `*.x` vale para cualquier subdominio de x (no para x).
     */
    public static function isAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (strtolower($parts['scheme']) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $host = rtrim(strtolower($parts['host']), '.');

        if ($host === '') {
            return false;
        }

        foreach (self::allowedHosts() as $pattern) {
            if (self::hostMatches($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El cifrado (en flush) revienta con llaves que no son base64url válido
     * del tamaño esperado y tumbaría el lote entero.
     */
    public static function hasValidKeys(string $publicKey, string $authToken): bool
    {
        return self::isValidPublicKey($publicKey) && self::isValidAuthToken($authToken);
    }

    /** Llave P-256 sin comprimir: exactamente 65 bytes. */
    public static function isValidPublicKey(string $publicKey): bool
    {
        $decoded = self::decodeBase64Url($publicKey);

        return $decoded !== null && strlen($decoded) === 65;
    }

    /** Secreto de autenticación: al menos 16 bytes. */
    public static function isValidAuthToken(string $authToken): bool
    {
        $decoded = self::decodeBase64Url($authToken);

        return $decoded !== null && strlen($decoded) >= 16;
    }

    private static function decodeBase64Url(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private static function hostMatches(string $host, string $pattern): bool
    {
        $pattern = strtolower(trim($pattern));

        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1);

            return strlen($host) > strlen($suffix) && str_ends_with($host, $suffix);
        }

        return $pattern !== '' && $host === $pattern;
    }

    /**
     * @return list<string>
     */
    private static function allowedHosts(): array
    {
        $hosts = config('webpush.allowed_hosts', []);

        return is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [];
    }
}
