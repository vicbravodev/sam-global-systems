<?php

namespace Tests\Feature\Console;

use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `samsara:connect` escribe credenciales de un tenant: nunca debe adivinar a
 * cuál (antes caía en el primer Team de la DB).
 */
class SamsaraConnectCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fails_without_an_explicit_team(): void
    {
        User::factory()->create();

        $this->artisan('samsara:connect', ['token' => 'api-token', '--webhook' => true, '--secret' => 'samsara-secret'])
            ->expectsOutputToContain('--team')
            ->assertFailed();

        $this->assertSame(0, TenantIntegration::withoutGlobalScopes()->count());
        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    public function test_it_fails_for_an_unknown_team(): void
    {
        User::factory()->create();

        $this->artisan('samsara:connect', ['token' => 'api-token', '--team' => 999999])
            ->assertFailed();

        $this->assertSame(0, TenantIntegration::withoutGlobalScopes()->count());
    }
}
