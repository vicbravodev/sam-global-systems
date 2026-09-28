<?php

namespace App\Domains\Notifications\Support;

/**
 * URL pública a la que Twilio reporta el estado de mensajes y llamadas.
 *
 * `services.twilio.status_callback_url` la fija explícitamente (útil detrás
 * de un proxy o un túnel); si no, se deriva de la ruta nombrada. Una URL que
 * Twilio no puede alcanzar (localhost, *.test, IP privada) no se envía: el
 * reconciliador consulta el estado igualmente, así que en local el feedback
 * sigue llegando, sólo que por polling.
 */
final class TwilioStatusCallbackUrl
{
    public static function resolve(): ?string
    {
        $configured = config('services.twilio.status_callback_url');

        $url = is_string($configured) && $configured !== ''
            ? $configured
            : route('webhooks.twilio.status');

        return self::isPublic($url) ? $url : null;
    }

    public static function isPublic(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($host) || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($host);

        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test') || str_ends_with($host, '.local')
            || ! str_contains($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }
}
