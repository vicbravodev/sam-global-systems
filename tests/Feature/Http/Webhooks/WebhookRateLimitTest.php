<?php

namespace Tests\Feature\Http\Webhooks;

use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El throttle de webhooks va por endpoint (bucket por tenant) con un techo
 * holgado por IP: todo el tráfico de Samsara llega desde pocas IPs, y un único
 * bucket por IP haría que el flood de un tenant tirara los pánicos de otros.
 */
class WebhookRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const PER_ENDPOINT = 600;

    private const PER_IP = 3000;

    protected function setUp(): void
    {
        parent::setUp();

        $limiter = $this->app->make(RateLimiter::class);
        $limiter->clear($this->endpointKey('tenant-a'));
        $limiter->clear($this->endpointKey('tenant-b'));
        $limiter->clear($this->ipKey());
    }

    public function test_webhook_accepts_requests_below_limit(): void
    {
        $response = $this->postJson('/api/webhooks/nonexistent-endpoint', []);

        $this->assertNotSame(429, $response->status(), 'First request should not be throttled');
    }

    public function test_an_exhausted_endpoint_bucket_does_not_throttle_other_tenants(): void
    {
        $this->exhaust($this->endpointKey('tenant-a'), self::PER_ENDPOINT);

        $this->postJson('/api/webhooks/tenant-a', [])->assertStatus(429);
        $this->assertNotSame(429, $this->postJson('/api/webhooks/tenant-b', [])->status());
    }

    public function test_per_ip_ceiling_still_applies(): void
    {
        $this->exhaust($this->ipKey(), self::PER_IP);

        $this->postJson('/api/webhooks/tenant-b', [])->assertStatus(429);
    }

    private function exhaust(string $key, int $times): void
    {
        $limiter = $this->app->make(RateLimiter::class);

        for ($i = 0; $i < $times; $i++) {
            $limiter->hit($key, 60);
        }
    }

    /**
     * ThrottleRequests hashes named-limiter keys as md5($name . $limit->key).
     */
    private function endpointKey(string $endpoint): string
    {
        return md5('webhooksendpoint:'.$endpoint);
    }

    private function ipKey(): string
    {
        return md5('webhooksip:127.0.0.1');
    }
}
