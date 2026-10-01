<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Proxies de confianza leídos de config (TRUSTED_PROXIES), no de env() en
 * bootstrap/app.php: con `config:cache` env() devuelve null fuera de config/.
 *
 * Detrás del balanceador TLS hace falta confiar en él para que `fullUrl()`
 * (firma de Twilio), `isSecure()` y `ip()` (throttles por IP) vean al cliente
 * real y no al proxy. Por defecto no se confía en ninguno.
 */
class TrustProxiesFromConfig extends TrustProxies
{
    /**
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));

        if ($configured === '') {
            return parent::proxies();
        }

        if ($configured === '*' || $configured === '**') {
            return $configured;
        }

        // Descarta entradas vacías (comas dobles o finales); '0' tampoco es un
        // proxy válido y se seguía descartando con el array_filter sin callback.
        return array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            static fn (string $proxy): bool => $proxy !== '' && $proxy !== '0',
        ));
    }
}
