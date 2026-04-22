<?php

namespace Tests\Feature\Http\Webhooks;

use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(RateLimiter::class)->clear($this->throttleKey());
    }

    public function test_webhook_accepts_requests_below_limit(): void
    {
        $response = $this->postJson('/api/webhooks/nonexistent-endpoint', []);

        $this->assertNotSame(429, $response->status(), 'First request should not be throttled');
    }

    public function test_webhook_returns_429_after_exceeding_limit(): void
    {
        $limiter = $this->app->make(RateLimiter::class);
        $key = $this->throttleKey();

        for ($i = 0; $i < 300; $i++) {
            $limiter->hit($key, 60);
        }

        $response = $this->postJson('/api/webhooks/any-endpoint', []);

        $response->assertStatus(429);
    }

    /**
     * Laravel's ThrottleRequests middleware hashes the named-limiter key as md5($name . $limitBy).
     * Our 'webhooks' limiter uses $request->ip() as the "by" value.
     */
    private function throttleKey(): string
    {
        return md5('webhooks127.0.0.1');
    }
}
