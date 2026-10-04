<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Command;
use Laravel\Nightwatch\Records\Exception;
use Laravel\Nightwatch\Records\Mail;
use Laravel\Nightwatch\Records\OutgoingRequest;
use Laravel\Nightwatch\Records\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Redactores de Nightwatch: lo que sale de SAM hacia ese tercero nunca lleva
 * una credencial ni algo que identifique a una persona. Mismas reglas que
 * `RedactSensitiveLogData` (la red de los logs), más lo propio de una URL
 * entrante: el parámetro de ruta que ES la credencial (`webhooks/{endpoint_url}`,
 * `bienvenida/{token}`) y los valores del query string.
 *
 * Los payloads de request no se capturan nunca (config/nightwatch.php) y las
 * queries llegan con placeholders, sin bindings: no necesitan redactor.
 */
final class NightwatchPrivacy
{
    /**
     * Parámetros de ruta cuyo valor es una credencial.
     *
     * @var list<string>
     */
    public const array SECRET_ROUTE_PARAMETERS = ['token', 'hash', 'endpoint_url', 'signature', 'secret'];

    public static function redactRequest(Request $request): void
    {
        $request->url = self::requestUrl($request->url, $request->routePath);
        $request->ip = self::anonymizeIp($request->ip);
    }

    public static function redactException(Exception $exception): void
    {
        $exception->message = RedactSensitiveLogData::sanitize($exception->message);
    }

    public static function redactOutgoingRequest(OutgoingRequest $request): void
    {
        $request->url = RedactSensitiveLogData::sanitize($request->url);
    }

    public static function redactCommand(Command $command): void
    {
        $command->command = RedactSensitiveLogData::sanitize($command->command);
    }

    public static function redactMail(Mail $mail): void
    {
        $mail->subject = RedactSensitiveLogData::sanitize($mail->subject);
    }

    /**
     * Las claves del rate limiter de login llevan el email (`email|ip`).
     */
    public static function redactCacheEvent(CacheEvent $event): void
    {
        $event->key = RedactSensitiveLogData::sanitize($event->key);
    }

    /**
     * Nightwatch siempre manda el id; nombre y email no salen de SAM. El team
     * activo ya viaja en el Context (`tenant_id`, `team_id`).
     *
     * @return array{}
     */
    public static function userDetails(Authenticatable $user): array
    {
        return [];
    }

    private static function requestUrl(string $url, string $routePath): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return RedactSensitiveLogData::sanitize($url);
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = self::redactPath($parts['path'] ?? '/', $routePath);
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.self::redactQuery($parts['query']) : '';

        return $origin.$path.$query;
    }

    /**
     * Sustituye por `[redacted]` cada segmento que corresponde a un parámetro
     * secreto de la plantilla. Si el path no se alinea con la plantilla
     * (prefijo, parámetro opcional), se manda la plantilla tal cual.
     */
    private static function redactPath(string $path, string $routePath): string
    {
        if ($routePath === '' || ! self::hasSecretParameter($routePath)) {
            return $path;
        }

        $actual = explode('/', $path);
        $template = explode('/', $routePath);

        if (count($actual) !== count($template)) {
            return $routePath;
        }

        foreach ($template as $index => $segment) {
            if (self::isSecretParameter($segment)) {
                $actual[$index] = RedactSensitiveLogData::MASK;
            }
        }

        return implode('/', $actual);
    }

    private static function hasSecretParameter(string $routePath): bool
    {
        foreach (explode('/', $routePath) as $segment) {
            if (self::isSecretParameter($segment)) {
                return true;
            }
        }

        return false;
    }

    private static function isSecretParameter(string $segment): bool
    {
        return preg_match('/^\{(\w+)\??\}$/', $segment, $match) === 1
            && in_array($match[1], self::SECRET_ROUTE_PARAMETERS, true);
    }

    /**
     * Conserva qué filtros se usaron, nunca sus valores (una búsqueda puede
     * ser el nombre de un conductor; `signature`/`expires` firman la URL).
     */
    private static function redactQuery(string $query): string
    {
        $keys = [];

        foreach (explode('&', $query) as $pair) {
            $key = urldecode(explode('=', $pair, 2)[0]);

            if ($key !== '') {
                $keys[] = $key.'='.RedactSensitiveLogData::MASK;
            }
        }

        return implode('&', $keys);
    }

    private static function anonymizeIp(string $ip): string
    {
        try {
            return $ip === '' ? $ip : IpUtils::anonymize($ip);
        } catch (Throwable) {
            return RedactSensitiveLogData::MASK;
        }
    }
}
