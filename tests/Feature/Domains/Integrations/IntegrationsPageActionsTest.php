<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Events\IntegrationDisconnected;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IntegrationsPageActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_store_web_route_creates_integration_and_webhook(): void
    {
        Event::fake([IntegrationConnected::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $response = $this->actingAs($user)->postJson(
            route('integrations.store', ['current_team' => $team->slug]),
            [
                'provider_id' => $provider->id,
                'name' => 'Nueva conexión',
                'auth_type' => 'api_key',
                'credentials' => 'a-secret-key',
                'config' => ['refresh_interval' => 300],
            ],
        );

        $response->assertCreated();

        $integration = TenantIntegration::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($team->id, $integration->team_id);
        $this->assertSame(TenantIntegrationStatus::Active, $integration->status);
        $this->assertDatabaseHas('webhook_endpoints', [
            'tenant_integration_id' => $integration->id,
        ]);

        Event::assertDispatched(IntegrationConnected::class);
    }

    public function test_update_web_route_changes_name_and_config(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Antiguo',
        ]);

        $response = $this->actingAs($user)->putJson(
            route('integrations.update', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
            ['name' => 'Renombrado', 'config' => ['timeout' => 60]],
        );

        $response->assertOk();
        $integration->refresh();
        $this->assertSame('Renombrado', $integration->name);
        $this->assertSame(['timeout' => 60], $integration->config_json);
    }

    public function test_test_web_route_runs_connection_check(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();
        $integration = TenantIntegration::factory()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'status' => TenantIntegrationStatus::Pending,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('integrations.test', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
        );

        $response->assertOk();
        $response->assertJsonPath('data.success', true);

        $integration->refresh();
        $this->assertSame(TenantIntegrationStatus::Active, $integration->status);
    }

    public function test_destroy_web_route_marks_inactive_and_deletes(): void
    {
        Event::fake([IntegrationDisconnected::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
        ]);

        $response = $this->actingAs($user)->deleteJson(
            route('integrations.destroy', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
        );

        $response->assertNoContent();
        $this->assertDatabaseMissing('tenant_integrations', ['id' => $integration->id]);

        Event::assertDispatched(IntegrationDisconnected::class);
    }

    public function test_manage_actions_require_manage_permission(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $provider = IntegrationProvider::factory()->create();
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
        ]);

        // View-only member can read the page but cannot manage.
        $viewer = $this->memberWithPermissions($team, ['integrations.view']);

        $this->actingAs($viewer)
            ->get(route('integrations.index', ['current_team' => $team->slug]))
            ->assertOk();

        $this->actingAs($viewer)->postJson(
            route('integrations.store', ['current_team' => $team->slug]),
            [
                'provider_id' => $provider->id,
                'name' => 'Nope',
                'auth_type' => 'api_key',
                'credentials' => 'x',
            ],
        )->assertForbidden();

        $this->actingAs($viewer)->putJson(
            route('integrations.update', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
            ['name' => 'Hijack'],
        )->assertForbidden();

        $this->actingAs($viewer)->deleteJson(
            route('integrations.destroy', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
        )->assertForbidden();

        $this->actingAs($viewer)->postJson(
            route('integrations.test', [
                'current_team' => $team->slug,
                'integration' => $integration->id,
            ]),
        )->assertForbidden();
    }

    public function test_actions_are_tenant_isolated(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $other = User::factory()->create();
        $provider = IntegrationProvider::factory()->create();
        $foreign = TenantIntegration::factory()->active()->create([
            'team_id' => $other->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'No tocar',
        ]);

        $response = $this->actingAs($user)->putJson(
            route('integrations.update', [
                'current_team' => $team->slug,
                'integration' => $foreign->id,
            ]),
            ['name' => 'Hijacked'],
        );

        // The BelongsToTenant route binding scopes to the current team, so the
        // foreign integration is invisible (404) — never reachable for 403.
        $this->assertContains($response->status(), [403, 404]);

        $foreign->refresh();
        $this->assertSame('No tocar', $foreign->name);
    }

    /**
     * Attach a fresh user to the given team with a tenant role granting exactly
     * the supplied permission codes.
     *
     * @param  array<string>  $codes
     */
    private function memberWithPermissions(Team $team, array $codes): User
    {
        $user = User::factory()->create();

        $role = Role::factory()->create([
            'code' => 'integrations-test-role-'.uniqid(),
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($codes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => explode('.', $code, 2)[0]],
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $team->members()->attach($user, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return $user;
    }
}
