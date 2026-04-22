<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Events\IntegrationDisconnected;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IntegrationCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_tenant_integration_with_encrypted_credentials(): void
    {
        Event::fake([IntegrationConnected::class, IntegrationStatusChanged::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $response = $this->actingAs($user)->postJson(
            route('api.integrations.store', ['current_team' => $team->slug]),
            [
                'provider_id' => $provider->id,
                'name' => 'My Samsara Connection',
                'auth_type' => 'api_key',
                'credentials' => 'super-secret-api-key-12345',
                'config' => ['refresh_interval' => 300],
            ],
        );

        $response->assertCreated();

        $integration = TenantIntegration::withoutGlobalScopes()->first();

        $this->assertNotNull(
            $integration,
            'Tenant integration should be created in the database after a valid store request',
        );

        $this->assertEquals(
            $team->id,
            $integration->team_id,
            'Integration should belong to the current team',
        );

        $this->assertEquals(
            TenantIntegrationStatus::Active,
            $integration->status,
            'Newly created integration should have active status',
        );

        $this->assertDatabaseHas('webhook_endpoints', [
            'tenant_integration_id' => $integration->id,
        ]);

        $this->assertNotEquals(
            'super-secret-api-key-12345',
            $integration->getRawOriginal('credentials_encrypted'),
            'Credentials must be encrypted at rest — raw DB value should differ from plaintext',
        );

        Event::assertDispatched(IntegrationConnected::class);
        Event::assertDispatched(IntegrationStatusChanged::class);
    }

    public function test_it_lists_only_integrations_belonging_to_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Team Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'encrypted-value',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $otherTeam = Team::factory()->create();
        TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Other Team Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'encrypted-value',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $response = $this->actingAs($user)->getJson(
            route('api.integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(
            1,
            $data,
            'API should return only integrations belonging to the current team, not other teams',
        );

        $this->assertEquals(
            'Team Integration',
            $data[0]['name'],
            'The returned integration should be the one belonging to the current team',
        );
    }

    public function test_it_updates_integration_config_and_credentials(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Original Name',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'old-secret',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $response = $this->actingAs($user)->putJson(
            route('api.integrations.update', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
            [
                'name' => 'Updated Name',
                'credentials' => 'new-super-secret',
                'config' => ['timeout' => 60],
            ],
        );

        $response->assertOk();

        $integration->refresh();

        $this->assertEquals(
            'Updated Name',
            $integration->name,
            'Integration name should be updated after a PUT request',
        );

        $this->assertEquals(
            ['timeout' => 60],
            $integration->config_json,
            'Integration config_json should be updated with the new config',
        );
    }

    public function test_it_deletes_integration_and_marks_inactive(): void
    {
        Event::fake([IntegrationDisconnected::class, IntegrationStatusChanged::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'To Be Deleted',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'some-secret',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $response = $this->actingAs($user)->deleteJson(
            route('api.integrations.destroy', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
        );

        $response->assertNoContent();

        $this->assertDatabaseMissing('tenant_integrations', [
            'id' => $integration->id,
        ]);

        Event::assertDispatched(IntegrationDisconnected::class, function ($event) use ($team, $integration) {
            return $event->teamId === $team->id && $event->integrationId === $integration->id;
        });

        Event::assertDispatched(IntegrationStatusChanged::class);
    }

    public function test_it_prevents_creating_integration_for_deprecated_provider(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->deprecated()->create();

        $response = $this->actingAs($user)->postJson(
            route('api.integrations.store', ['current_team' => $team->slug]),
            [
                'provider_id' => $provider->id,
                'name' => 'Should Fail',
                'auth_type' => 'api_key',
                'credentials' => 'some-key',
            ],
        );

        $response->assertStatus(422);

        $this->assertDatabaseMissing('tenant_integrations', [
            'provider_id' => $provider->id,
        ]);

        $this->assertEquals(
            0,
            TenantIntegration::withoutGlobalScopes()->where('provider_id', $provider->id)->count(),
            'No integration should be created when the provider is deprecated',
        );
    }
}
