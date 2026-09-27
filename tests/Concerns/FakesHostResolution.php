<?php

namespace Tests\Concerns;

use App\Support\Http\HostResolver;

/**
 * Sustituye la resolución DNS de OutboundUrlGuard: los tests no dependen de
 * la red y pueden simular hosts que resuelven a IPs públicas o internas.
 */
trait FakesHostResolution
{
    /**
     * @param  array<string, list<string>>  $records
     */
    protected function fakeDns(array $records): void
    {
        $this->app->instance(HostResolver::class, new class($records) extends HostResolver
        {
            /** @param array<string, list<string>> $records */
            public function __construct(private array $records) {}

            public function resolve(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        });
    }
}
