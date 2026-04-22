<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Actions\TestIntegrationConnection;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class IntegrationConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_success_when_provider_credentials_are_valid(): void
    {
        Event::fake([IntegrationStatusChanged::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Test Connection',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'valid-api-key',
            'status' => TenantIntegrationStatus::Pending,
        ]);

        $mockAdapter = Mockery::mock(ProviderAdapter::class);
        $mockAdapter->shouldReceive('testConnection')
            ->once()
            ->andReturn(['success' => true, 'message' => 'Connection successful']);

        $this->app->instance(ProviderAdapter::class, $mockAdapter);

        $action = app(TestIntegrationConnection::class);
        $result = $action->execute($integration);

        $this->assertTrue(
            $result['success'],
            'Test connection should return success when credentials are valid',
        );

        $integration->refresh();

        $this->assertEquals(
            TenantIntegrationStatus::Active,
            $integration->status,
            'Integration status should be updated to Active after successful connection test',
        );

        $this->assertNull(
            $integration->last_error_message,
            'Last error message should be cleared after successful connection test',
        );

        Event::assertDispatched(IntegrationStatusChanged::class);
    }

    public function test_it_returns_failure_when_provider_credentials_are_invalid(): void
    {
        Event::fake([IntegrationStatusChanged::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Test Bad Connection',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'invalid-api-key',
            'status' => TenantIntegrationStatus::Pending,
        ]);

        $mockAdapter = Mockery::mock(ProviderAdapter::class);
        $mockAdapter->shouldReceive('testConnection')
            ->once()
            ->andReturn(['success' => false, 'message' => 'Invalid API key']);

        $this->app->instance(ProviderAdapter::class, $mockAdapter);

        $action = app(TestIntegrationConnection::class);
        $result = $action->execute($integration);

        $this->assertFalse(
            $result['success'],
            'Test connection should return failure when credentials are invalid',
        );

        $integration->refresh();

        $this->assertEquals(
            TenantIntegrationStatus::Error,
            $integration->status,
            'Integration status should be updated to Error after failed connection test',
        );

        $this->assertEquals(
            'Invalid API key',
            $integration->last_error_message,
            'Last error message should contain the failure reason from the provider',
        );

        $this->assertNotNull(
            $integration->last_error_at,
            'Last error timestamp should be set after a failed connection test',
        );

        Event::assertDispatched(IntegrationStatusChanged::class);
    }
}
