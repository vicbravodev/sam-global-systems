<?php

namespace App\Support\Http;

/**
 * Resolución DNS (A + AAAA) de un host. Aislada en su propia clase para que
 * los tests la sustituyan sin depender de la red.
 */
class HostResolver
{
    /**
     * @return list<string> Direcciones IP a las que resuelve el host.
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        $addresses = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $addresses[] = $ip;
            }
        }

        if ($addresses === []) {
            $resolved = @gethostbynamel($host);
            $addresses = $resolved !== false ? $resolved : [];
        }

        return array_values(array_unique($addresses));
    }
}
