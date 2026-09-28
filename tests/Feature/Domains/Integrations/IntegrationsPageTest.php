<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Enums\IntegrationProblem;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class IntegrationsPageTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
                        ->where('authTypeLabel', 'Clave de API')
                        ->has('capabilities')
                        ->has('connectedAt')
                        ->has('lastLocationAt')
                        ->where('problem', null)
                        ->where('events24h', 0)
                        ->has('fleet')
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
                ->where('integrations.0.lastErrorMessage', 'Connection refused')
                ->where('integrations.0.problem', 'unreachable'),
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

    public function test_summary_and_rows_describe_only_the_current_tenant(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $active = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
        ]);
        TenantIntegration::factory()->error()->create([
            'team_id' => $team->id,
            'provider_id' => IntegrationProvider::factory()->create()->id,
            'last_error_message' => 'Samsara rejected the API token (HTTP 401).',
        ]);
        $this->eventFor($active);
        $this->eventFor($active);
        $liveAt = now()->subMinutes(2)->startOfSecond();
        TelematicsFeedCursor::factory()->create([
            'tenant_integration_id' => $active->id,
            'last_data_at' => $liveAt,
        ]);

        Asset::factory()->count(3)->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'monitoring_state' => AssetMonitoringState::Pending,
        ]);
        Asset::factory()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'monitoring_state' => AssetMonitoringState::Monitored,
        ]);
        DriverExternalReference::factory()->create([
            'driver_id' => Driver::factory()->create(['team_id' => $team->id])->id,
            'provider_id' => $provider->id,
        ]);

        // Another tenant on the same provider: none of this may be counted.
        $other = User::factory()->create();
        $otherTeam = $other->currentTeam;
        $foreign = TenantIntegration::factory()->active()->create([
            'team_id' => $otherTeam->id,
            'provider_id' => $provider->id,
        ]);
        $this->eventFor($foreign);
        Asset::factory()->count(5)->create([
            'team_id' => $otherTeam->id,
            'provider_id' => $provider->id,
            'monitoring_state' => AssetMonitoringState::Monitored,
        ]);
        DriverExternalReference::factory()->create([
            'driver_id' => Driver::factory()->create(['team_id' => $otherTeam->id])->id,
            'provider_id' => $provider->id,
        ]);

        $response = $this->actingAs($user)->get(
            route('integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('integrations/index')
                ->where('summary.total', 2)
                ->where('summary.working', 1)
                ->where('summary.attention', 1)
                ->where('summary.pending', 0)
                ->where('summary.events24h', 2)
                ->where('summary.assets', 4)
                ->where('summary.monitored', 1)
                ->where('summary.drivers', 1)
                ->where('integrations.0.problem', IntegrationProblem::Credentials->value)
                ->where('integrations.1.id', $active->id)
                ->where('integrations.1.events24h', 2)
                ->where('integrations.1.lastLocationAt', $liveAt->toIso8601String())
                ->where('integrations.0.lastLocationAt', null)
                ->where('integrations.1.fleet.assets', 4)
                ->where('integrations.1.fleet.monitored', 1)
                ->where('integrations.1.fleet.drivers', 1),
        );
    }

    public function test_fleet_is_not_attributed_when_a_provider_has_several_integrations(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        TenantIntegration::factory()->count(2)->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
        ]);
        Asset::factory()->create(['team_id' => $team->id, 'provider_id' => $provider->id]);

        $this->actingAs($user)
            ->get(route('integrations.index', ['current_team' => $team->slug]))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('integrations.0.fleet', null)
                    ->where('integrations.1.fleet', null)
                    ->where('summary.assets', 1),
            );
    }

    public function test_rendering_the_page_as_one_tenant_never_touches_another(): void
    {
        $provider = IntegrationProvider::factory()->samsara()->create();

        $owner = User::factory()->create();
        $foreign = TenantIntegration::factory()->active()->create([
            'team_id' => $owner->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Integración ajena',
        ]);
        $this->eventFor($foreign);

        $viewer = User::factory()->create();
        $viewerTeam = $viewer->currentTeam;
        TenantIntegration::factory()->active()->create([
            'team_id' => $viewerTeam->id,
            'provider_id' => $provider->id,
        ]);

        $response = $this->assertNoTenantLeak($viewerTeam, fn () => $this->actingAs($viewer)->get(
            route('integrations.index', ['current_team' => $viewerTeam->slug]),
        ));

        $response->assertOk();
        $this->assertStringNotContainsString('Integración ajena', json_encode($response->viewData('page')['props']));
        $response->assertInertia(
            fn (Assert $page) => $page
                ->has('integrations', 1)
                ->where('integrations.0.events24h', 0)
                ->where('summary.events24h', 0),
        );
    }

    public function test_problem_classifier_explains_common_provider_errors(): void
    {
        $this->assertNull(IntegrationProblem::classify(null));
        $this->assertSame(IntegrationProblem::Credentials, IntegrationProblem::classify('401 Unauthorized: el token de API fue revocado'));
        $this->assertSame(IntegrationProblem::RateLimited, IntegrationProblem::classify('HTTP 429 Too Many Requests'));
        $this->assertSame(IntegrationProblem::Unreachable, IntegrationProblem::classify('Could not reach Samsara: cURL error 28'));
        $this->assertSame(IntegrationProblem::Provider, IntegrationProblem::classify('Samsara returned HTTP 500.'));
        $this->assertSame(IntegrationProblem::Unknown, IntegrationProblem::classify('Algo raro pasó'));
    }

    private function eventFor(TenantIntegration $integration): NormalizedEvent
    {
        $source = EventSource::factory()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'tenant_integration_id' => $integration->id,
        ]);

        $raw = RawEvent::factory()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'event_source_id' => $source->id,
        ]);

        return NormalizedEvent::factory()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'raw_event_id' => $raw->id,
            'occurred_at' => now()->subHour(),
        ]);
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
