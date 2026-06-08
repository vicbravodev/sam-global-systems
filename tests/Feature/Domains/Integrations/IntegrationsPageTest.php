<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_page_renders_inertia_component_with_integrations(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Producción',
        ]);
        WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('integrations/index')
                ->has('integrations', 1)
                ->has(
                    'integrations.0',
                    fn (Assert $row) => $row
                        ->where('id', $integration->id)
                        ->where('name', 'Samsara Producción')
                        ->where('provider', 'Samsara')
                        ->where('providerCode', 'samsara')
                        ->where('status', 'active')
                        ->where('health', 'ok')
                        ->where('authType', 'api_key')
                        ->has('config')
                        ->has('lastSyncAt')
                        ->has('lastErrorAt')
                        ->has('lastErrorMessage')
                        ->has(
                            'webhook',
                            fn (Assert $webhook) => $webhook
                                ->where('url', fn ($url) => is_string($url) && str_contains((string) $url, '/webhooks/'))
                                ->has('status')
                                ->has('lastReceivedAt'),
                        ),
                )
                ->has('providers')
                ->has('authTypes'),
        );
    }

    public function test_page_maps_status_to_health(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        TenantIntegration::factory()->error()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->where('integrations.0.status', 'error')
                ->where('integrations.0.health', 'down')
                ->where('integrations.0.lastErrorMessage', 'Connection refused'),
        );
    }

    public function test_page_only_exposes_current_team_integrations(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        TenantIntegration::factory()->count(2)->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
        ]);

        $other = User::factory()->create();
        TenantIntegration::factory()->create([
            'team_id' => $other->currentTeam->id,
            'provider_id' => $provider->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('integrations/index')
                ->has('integrations', 2),
        );
    }

    public function test_available_providers_exclude_deprecated(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        IntegrationProvider::factory()->create(['name' => 'Active Provider']);
        IntegrationProvider::factory()->deprecated()->create(['name' => 'Old Provider']);

        $response = $this->actingAs($user)->get(
            route('integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(function (Assert $page) {
            $names = collect($page->toArray()['props']['providers'])
                ->pluck('name')
                ->all();

            $this->assertContains('Active Provider', $names);
            $this->assertNotContains('Old Provider', $names);
        });
    }

    public function test_page_does_not_serialize_webhook_secret_or_credentials(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'credentials_encrypted' => 'super-secret-credential-value',
        ]);
        WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
            'secret' => 'WEBHOOK_SECRET_DO_NOT_LEAK',
        ]);

        $response = $this->actingAs($user)->get(
            route('integrations.index', ['current_team' => $team->slug]),
        );

        $payload = json_encode($response->viewData('page')['props']);

        $this->assertStringNotContainsString('WEBHOOK_SECRET_DO_NOT_LEAK', $payload);
        $this->assertStringNotContainsString('super-secret-credential-value', $payload);
        $this->assertStringNotContainsString('credentials_encrypted', $payload);
    }

    public function test_page_requires_view_permission(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;

        $stranger = $this->memberWithPermissions($team, []);

        $response = $this->actingAs($stranger)->get(
            route('integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertForbidden();
    }

    /**
     * Attach a fresh user to the given team with a tenant role granting exactly
     * the supplied permission codes (none by default).
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
