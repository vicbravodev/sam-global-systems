<?php

namespace App\Support\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Protección SSRF para peticiones salientes a URLs que controla un tenant
 * (webhooks de automatización).
 *
 * Exige https (http sólo si `services.outbound_webhooks.allow_http`, pensado
 * para local/testing), rechaza credenciales en la URL, nombres reservados
 * (localhost, *.internal, metadata...) y cualquier IP privada, loopback,
 * link-local (metadata de la nube), CGNAT, multicast o unique-local IPv6 —
 * tanto si viene literal como si es a lo que resuelve el DNS. Devuelve las IPs
 * validadas para fijarlas en la conexión (ver OutboundTarget::httpOptions).
 */
class OutboundUrlGuard
{
    private const EXTRA_BLOCKED_SUBNETS = [
        '224.0.0.0/4',  // multicast
        '192.0.0.0/24', // IETF protocol assignments
        '255.255.255.255/32',
        'ff00::/8',     // multicast IPv6
    ];

    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata',
        'metadata.google.internal',
        'instance-data',
    ];

    private const BLOCKED_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.localdomain',
        '.home.arpa',
    ];

    public function __construct(
        private readonly HostResolver $resolver,
    ) {}

    /**
     * @throws UnsafeOutboundUrlException
     */
    public function assertSafe(string $url): OutboundTarget
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeOutboundUrlException('malformed_url');
        }

        $scheme = strtolower($parts['scheme']);
        $allowed = $this->allowHttp() ? ['https', 'http'] : ['https'];

        if (! in_array($scheme, $allowed, true)) {
            throw new UnsafeOutboundUrlException("scheme_not_allowed:{$scheme}");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeOutboundUrlException('credentials_in_url');
        }

        $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if ($host === '' || $this->isReservedHostname($host)) {
            throw new UnsafeOutboundUrlException("reserved_host:{$host}");
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif (preg_match('/^[0-9.]+$|^0x/i', $host) === 1) {
            // IPs en notación decimal/octal/hex ("2130706433", "0x7f.1"): los
            // clientes HTTP las interpretan como IP, no las dejamos pasar.
            throw new UnsafeOutboundUrlException("numeric_host:{$host}");
        } else {
            $addresses = $this->resolver->resolve($host);
        }

        if ($addresses === []) {
            throw new UnsafeOutboundUrlException("unresolvable_host:{$host}");
        }

        foreach ($addresses as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new UnsafeOutboundUrlException("blocked_ip:{$ip}");
            }
        }

        return new OutboundTarget($url, $host, $port, $addresses);
    }

    public function isSafe(string $url): bool
    {
        try {
            $this->assertSafe($url);

            return true;
        } catch (UnsafeOutboundUrlException) {
            return false;
        }
    }

    private function allowHttp(): bool
    {
        return (bool) config('services.outbound_webhooks.allow_http', false);
    }

    private function isReservedHostname(string $host): bool
    {
        if (in_array($host, self::BLOCKED_HOSTS, true) || ! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return IpUtils::isPrivateIp($ip)
            || IpUtils::checkIp($ip, self::EXTRA_BLOCKED_SUBNETS)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
