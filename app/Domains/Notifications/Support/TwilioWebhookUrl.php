<?php

namespace App\Domains\Notifications\Support;

use Illuminate\Http\Request;

/**
 * URL contra la que se valida la firma `X-Twilio-Signature` de un webhook.
 *
 * Twilio firma la URL pública exacta a la que llamó. Detrás de un proxy o un
 * terminador TLS, Laravel puede ver otro esquema o host (`http://app:80/...`)
 * y toda firma falla: un "presiona 1" o una respuesta SI terminaban en 403 y
 * se perdía el reconocimiento. Con `services.twilio.public_base_url` se
 * reemplaza esquema + host (+ puerto) por el público, conservando ruta y
 * query tal cual.
 */
final class TwilioWebhookUrl
{
    public static function forSignature(Request $request): string
    {
        $base = config('services.twilio.public_base_url');
        $url = $request->fullUrl();

        if (! is_string($base) || trim($base) === '') {
            return $url;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return rtrim(trim($base), '/').$path.(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    /**
     * URL pública de una ruta nombrada para dársela a Twilio (acción de un
     * Gather, status callback): con la base pública configurada, esa base +
     * la ruta; si no, la URL absoluta de siempre (APP_URL).
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        $base = config('services.twilio.public_base_url');

        if (! is_string($base) || trim($base) === '') {
            return route($name, $parameters);
        }

        return rtrim(trim($base), '/').route($name, $parameters, false);
    }
}
