<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Samsara genera la Secret Key del webhook: el tenant la copia a SAM desde su
 * pantalla de Integraciones. Se guarda cifrada y nunca vuelve al navegador.
 */
class WebhookSecretConfigurationTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const string SECRET = 'c2Ftc2FyYS1zZWNyZXQta2V5LWZvci10ZXN0cw==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    /**
     * @return array{0: User, 1: Team, 2: TenantIntegration, 3: WebhookEndpoint}
     */
    private function connectedSamsara(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $this->samsara()->id,
        ]);
        $endpoint = WebhookEndpoint::factory()->withoutSecret()->create(['tenant_integration_id' => $integration->id]);

        return [$user, $team, $integration, $endpoint];
    }

    private function samsara(): IntegrationProvider
    {
        return IntegrationProvider::query()->where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();
    }

    private function secretRoute(Team $team, TenantIntegration $integration, string $name = 'integrations.webhook-secret.update'): string
    {
        return route($name, ['current_team' => $team->slug, 'integration' => $integration->id]);
    }

    public function test_connecting_an_integration_leaves_the_webhook_secret_pending(): void
    {
        Event::fake([IntegrationConnected::class]);
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $this->actingAs($user)->postJson(route('integrations.store', ['current_team' => $team->slug]), [
            'provider_id' => $provider->id,
            'name' => 'Samsara',
            'auth_type' => 'api_key',
            'credentials' => 'api-token',
        ])->assertCreated();

        $endpoint = WebhookEndpoint::query()->sole();
        $this->assertNull($endpoint->secret);
        $this->assertFalse($endpoint->hasSecret());
    }

    public function test_a_manager_stores_the_samsara_secret_encrypted(): void
    {
        [$user, $team, $integration, $endpoint] = $this->connectedSamsara();

        $response = $this->actingAs($user)
            ->putJson($this->secretRoute($team, $integration), ['webhook_secret' => '  '.self::SECRET.'  '])
            ->assertOk()
            ->assertJsonPath('data.webhook_secret_configured', true);

        $this->assertNotNull($response->json('data.webhook_secret_configured_at'));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        $endpoint->refresh();
        $this->assertSame(self::SECRET, $endpoint->secret);
        $this->assertNotNull($endpoint->secret_configured_at);

        $stored = WebhookEndpoint::query()->toBase()->whereKey($endpoint->id)->value('secret');
        $this->assertNotSame(self::SECRET, $stored);
        $this->assertStringNotContainsString(self::SECRET, (string) $stored);
    }

    public function test_the_api_route_stores_the_secret_too(): void
    {
        [$user, $team, $integration, $endpoint] = $this->connectedSamsara();

        $this->actingAs($user)
            ->putJson($this->secretRoute($team, $integration, 'api.integrations.webhook-secret.update'), ['webhook_secret' => self::SECRET])
            ->assertOk();

        $this->assertSame(self::SECRET, $endpoint->refresh()->secret);
    }

    public function test_storing_the_secret_is_audited_without_the_value(): void
    {
        [$user, $team, $integration, $endpoint] = $this->connectedSamsara();

        $this->actingAs($user)->putJson($this->secretRoute($team, $integration), ['webhook_secret' => self::SECRET])->assertOk();
        $this->actingAs($user)->putJson($this->secretRoute($team, $integration), ['webhook_secret' => self::SECRET.'x'])->assertOk();

        $entries = AuditLog::withoutGlobalScopes()->where('action', 'integration.webhook_secret.updated')->get();
        $this->assertCount(2, $entries, 'Cada rotación deja su propia entrada.');

        foreach ($entries as $entry) {
            $this->assertSame($team->id, $entry->team_id);
            $this->assertSame($user->id, $entry->actor_id);
            $this->assertSame($endpoint->id, $entry->metadata_json['webhook_endpoint_id']);
            $this->assertStringNotContainsString(self::SECRET, json_encode($entry->toArray()));
        }

        $this->assertFalse($entries[0]->metadata_json['replaced_existing']);
        $this->assertTrue($entries[1]->metadata_json['replaced_existing']);

        $this->assertSystemLogged('integrations.webhook_secret.updated', fn (array $c) => $c['input']['webhook_endpoint_id'] === $endpoint->id);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_secret_is_validated(): void
    {
        [$user, $team, $integration, $endpoint] = $this->connectedSamsara();

        foreach (['', 'short', str_repeat('a', 513), 'has spaces inside the secret', ['array']] as $invalid) {
            $this->actingAs($user)
                ->putJson($this->secretRoute($team, $integration), ['webhook_secret' => $invalid])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('webhook_secret');
        }

        $this->assertNull($endpoint->refresh()->secret);
    }

    public function test_a_member_without_manage_permission_cannot_store_the_secret(): void
    {
        [, $team, $integration, $endpoint] = $this->connectedSamsara();
        $viewer = $this->memberWithPermissions($team, ['integrations.view']);

        $this->actingAs($viewer)
            ->putJson($this->secretRoute($team, $integration), ['webhook_secret' => self::SECRET])
            ->assertForbidden();

        $this->assertNull($endpoint->refresh()->secret);
    }

    public function test_another_tenant_cannot_touch_a_foreign_endpoint(): void
    {
        [, , $foreignIntegration, $foreignEndpoint] = $this->connectedSamsara();
        [$intruder, $intruderTeam] = $this->connectedSamsara();

        $response = $this->actingAs($intruder)
            ->putJson($this->secretRoute($intruderTeam, $foreignIntegration), ['webhook_secret' => self::SECRET]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertNull($foreignEndpoint->refresh()->secret);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'integration.webhook_secret.updated')->count());
    }

    public function test_the_action_writes_nothing_outside_the_tenant(): void
    {
        [$user, $team, $integration, $endpoint] = $this->connectedSamsara();
        [, , , $foreignEndpoint] = $this->connectedSamsara();

        $this->assertNoTenantLeak($team, fn () => $this->actingAs($user)
            ->putJson($this->secretRoute($team, $integration), ['webhook_secret' => self::SECRET])
            ->assertOk());

        // webhook_endpoints no lleva team_id: el helper no lo ve, se asierta directo.
        $this->assertSame(self::SECRET, $endpoint->refresh()->secret);
        $this->assertNull($foreignEndpoint->refresh()->secret);
    }

    public function test_the_secret_never_travels_in_integration_responses_or_page_props(): void
    {
        [$user, $team, $integration, $endpoint] = $this->connectedSamsara();
        $endpoint->forceFill(['secret' => self::SECRET, 'secret_configured_at' => now()])->save();

        $index = $this->actingAs($user)->getJson(route('api.integrations.index', ['current_team' => $team->slug]))->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $index->getContent());

        $update = $this->actingAs($user)->putJson(route('integrations.update', ['current_team' => $team->slug, 'integration' => $integration->id]), ['name' => 'Renombrada'])->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $update->getContent());

        $page = $this->actingAs($user)->get(route('integrations.index', ['current_team' => $team->slug]))->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $page->getContent());
        $page->assertInertia(fn ($inertia) => $inertia
            ->component('integrations/index')
            ->where('integrations.0.webhook.secretConfigured', true)
            ->has('integrations.0.webhook.secretConfiguredAt')
            ->where('integrations.0.webhook.health', 'waiting')
            ->missing('integrations.0.webhook.secret'));
    }

    /**
     * @param  array<string>  $codes
     */
    private function memberWithPermissions(Team $team, array $codes): User
    {
        $user = User::factory()->create();

        $role = Role::factory()->create([
            'code' => 'webhook-secret-test-role-'.uniqid(),
            'scope' => RoleScope::Tenant,
        ]);

        $role->permissions()->sync(array_map(
            fn (string $code) => Permission::firstOrCreate(['code' => $code], ['name' => $code, 'module' => explode('.', $code, 2)[0]])->id,
            $codes,
        ));

        $team->members()->attach($user, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);
        // Está mirando ese team: si no, el binding con scope daría 404 antes de la Policy.
        $user->switchTeam($team);

        return $user;
    }
}
