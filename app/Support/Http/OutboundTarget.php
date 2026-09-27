<?php

namespace App\Support\Http;

/**
 * Destino ya validado por OutboundUrlGuard, con las IPs resueltas para
 * fijarlas en la conexión (evita DNS rebinding entre validar y conectar).
 */
final class OutboundTarget
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly array $addresses,
    ) {}

    /**
     * Opciones de Guzzle que fijan la resolución del host a las IPs validadas
     * y deshabilitan redirecciones (una redirección podría apuntar a la red
     * interna sin pasar por el guard).
     *
     * @return array<string, mixed>
     */
    public function httpOptions(): array
    {
        $options = ['allow_redirects' => false];

        if (defined('CURLOPT_RESOLVE') && filter_var($this->host, FILTER_VALIDATE_IP) === false) {
            $options['curl'] = [
                CURLOPT_RESOLVE => array_map(
                    fn (string $ip): string => sprintf(
                        '%s:%d:%s',
                        $this->host,
                        $this->port,
                        str_contains($ip, ':') ? "[{$ip}]" : $ip,
                    ),
                    $this->addresses,
                ),
            ];
        }

        return $options;
    }
}
