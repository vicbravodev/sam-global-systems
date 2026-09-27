<?php

namespace Tests\Feature\Support;

use App\Support\Http\HostResolver;
use App\Support\Http\OutboundUrlGuard;
use App\Support\Http\UnsafeOutboundUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutboundUrlGuardTest extends TestCase
{
    /**
     * @param  array<string, list<string>>  $dns
     */
    private function guard(array $dns = [], bool $allowHttp = false): OutboundUrlGuard
    {
        config(['services.outbound_webhooks.allow_http' => $allowHttp]);

        return new OutboundUrlGuard(new class($dns) extends HostResolver
        {
            /** @param array<string, list<string>> $dns */
            public function __construct(private array $dns) {}

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        });
    }

    public function test_public_https_host_is_allowed_and_resolution_is_pinned(): void
    {
        $target = $this->guard(['hooks.example.com' => ['93.184.216.34']])
            ->assertSafe('https://hooks.example.com/path?x=1');

        $this->assertSame('hooks.example.com', $target->host);
        $this->assertSame(443, $target->port);
        $this->assertSame(['93.184.216.34'], $target->addresses);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, list<string>>}>
     */
    public static function blockedTargets(): array
    {
        return [
            'http sin permiso' => ['http://hooks.example.com/', ['hooks.example.com' => ['93.184.216.34']]],
            'loopback literal' => ['https://127.0.0.1/', []],
            'metadata cloud' => ['https://169.254.169.254/latest/meta-data/', []],
            'red privada 10/8' => ['https://10.0.0.5/', []],
            'red privada 192.168' => ['https://192.168.1.1/', []],
            'cgnat' => ['https://100.64.0.1/', []],
            'ipv6 loopback' => ['https://[::1]/', []],
            'ipv6 unique local' => ['https://[fd00::1]/', []],
            'ipv6 mapeada a v4 privada' => ['https://[::ffff:10.0.0.1]/', []],
            'localhost' => ['https://localhost/', []],
            'subdominio de localhost' => ['https://api.localhost/', []],
            'metadata por nombre' => ['https://metadata.google.internal/', []],
            'dominio .internal' => ['https://db.internal/', []],
            'dns que apunta a privada' => ['https://rebind.example.com/', ['rebind.example.com' => ['93.184.216.34', '10.1.2.3']]],
            'dns sin resolver' => ['https://nx.example.com/', []],
            'credenciales en la url' => ['https://user:pass@hooks.example.com/', ['hooks.example.com' => ['93.184.216.34']]],
            'esquema file' => ['file:///etc/passwd', []],
            'esquema gopher' => ['gopher://hooks.example.com/', ['hooks.example.com' => ['93.184.216.34']]],
            'ip decimal' => ['https://2130706433/', []],
        ];
    }

    /**
     * @param  array<string, list<string>>  $dns
     */
    #[DataProvider('blockedTargets')]
    public function test_unsafe_targets_are_rejected(string $url, array $dns): void
    {
        $this->expectException(UnsafeOutboundUrlException::class);

        $this->guard($dns)->assertSafe($url);
    }

    public function test_http_is_allowed_only_when_configured(): void
    {
        $target = $this->guard(['hooks.example.com' => ['93.184.216.34']], allowHttp: true)
            ->assertSafe('http://hooks.example.com/');

        $this->assertSame(80, $target->port);
    }
}
