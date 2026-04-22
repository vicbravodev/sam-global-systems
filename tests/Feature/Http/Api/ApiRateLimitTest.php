<?php

namespace Tests\Feature\Http\Api;

use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_route_returns_429_after_exceeding_per_tenant_limit(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $limiter = $this->app->make(RateLimiter::class);
        $key = md5('api'.$team->slug);
        $limiter->clear($key);

        for ($i = 0; $i < 60; $i++) {
            $limiter->hit($key, 60);
        }

        $response = $this->getJson("/api/{$team->slug}/assets");

        $response->assertStatus(429);
    }

    public function test_api_route_accepts_requests_below_limit(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $this->actingAs($user);

        $this->app->make(RateLimiter::class)->clear(md5('api'.$team->slug));

        $response = $this->getJson("/api/{$team->slug}/assets");

        $this->assertNotSame(429, $response->status());
    }
}
