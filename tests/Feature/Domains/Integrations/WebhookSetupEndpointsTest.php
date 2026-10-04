<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Jobs\ProvisionSamsaraWebhookJob;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookSetupEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'c2FtLXNlY3JldA==';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        config(['services.samsara.webhook_base_url' => 'https://sam.example.com']);
    }

    /**
     * @param  array<string, mixed>  $endpoint
     */
    private function integration(Team $team, array $endpoint = []): TenantIntegration
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => IntegrationProvider::query()->firstWhere('code', 'samsara')?->id ?? IntegrationProvider::factory()->samsara()->create()->id,
            'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $integration->id, 'key' => 'api_token', 'value_encrypted' => 'sk-test']);
        WebhookEndpoint::factory()->withoutSecret()->create(['tenant_integration_id' => $integration->id, ...$endpoint]);

        return $integration;
    }

    private function fakeSamsara(): void
    {
        Http::fake([
            'api.samsara.com/webhooks/*' => Http::response(null, 204),
            'api.samsara.com/webhooks' => Http::response(['id' => 'wh-1', 'secretKey' => self::SECRET]),
            'api.samsara.com/alerts/configurations' => Http::response(['data' => ['id' => 'cfg-sam']]),
        ]);
    }

    public function test_provision_button_sets_up_the_webhook_and_never_returns_the_secret(): void
    {
        $this->fakeSamsara();
        $user = User::factory()->create();
        $integration = $this->integration($user->currentTeam);

        $response = $this->actingAs($user)->postJson(route('integrations.webhook.provision', [
            'current_team' => $user->currentTeam->slug, 'integration' => $integration->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.result', WebhookEndpoint::SETUP_STATUS_PROVISIONED)
            ->assertJsonPath('data.setup_mode', WebhookEndpoint::SETUP_AUTOMATIC);
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    public function test_rotate_button_returns_the_new_state(): void
    {
        $this->fakeSamsara();
        $user = User::factory()->create();
        $integration = $this->integration($user->currentTeam, [
            'secret' => 'b2xk',
            'setup_mode' => WebhookEndpoint::SETUP_AUTOMATIC,
            'setup_status' => WebhookEndpoint::SETUP_STATUS_PROVISIONED,
            'provider_webhook_id' => 'wh-old',
            'provider_alert_configuration_id' => 'cfg-sam',
        ]);

        $response = $this->actingAs($user)->postJson(route('integrations.webhook.rotate', [
            'current_team' => $user->currentTeam->slug, 'integration' => $integration->id,
        ]));

        $response->assertOk()->assertJsonPath('data.result', 'rotated');
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
    }

    public function test_a_provider_failure_answers_bad_gateway(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'boom'], 500)]);
        $user = User::factory()->create();
        $integration = $this->integration($user->currentTeam);

        $this->actingAs($user)->postJson(route('integrations.webhook.provision', [
            'current_team' => $user->currentTeam->slug, 'integration' => $integration->id,
        ]))->assertStatus(502)->assertJsonPath('data.setup_status', WebhookEndpoint::SETUP_STATUS_FAILED);
    }

    public function test_viewers_cannot_provision_or_rotate(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $integration = $this->integration($owner->currentTeam);
        $viewer = User::factory()->create();
        $role = Role::factory()->create(['code' => 'viewer-'.uniqid(), 'scope' => RoleScope::Tenant]);
        $role->permissions()->sync([Permission::firstOrCreate(['code' => 'integrations.view'], ['name' => 'v', 'module' => 'integrations'])->id]);
        $owner->currentTeam->members()->attach($viewer, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);

        foreach (['integrations.webhook.provision', 'integrations.webhook.rotate'] as $name) {
            $this->actingAs($viewer)->postJson(route($name, [
                'current_team' => $owner->currentTeam->slug, 'integration' => $integration->id,
            ]))->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_another_tenants_integration_is_not_found(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $foreign = $this->integration(User::factory()->create()->currentTeam);

        foreach (['integrations.webhook.provision', 'integrations.webhook.rotate'] as $name) {
            $this->actingAs($user)->postJson(route($name, [
                'current_team' => $user->currentTeam->slug, 'integration' => $foreign->id,
            ]))->assertNotFound();
        }

        Http::assertNothingSent();
        $this->assertFalse($foreign->webhookEndpoint()->firstOrFail()->hasSecret());
    }

    public function test_a_new_token_retries_a_setup_that_lacked_permissions(): void
    {
        Bus::fake([ProvisionSamsaraWebhookJob::class]);
        $user = User::factory()->create();
        $integration = $this->integration($user->currentTeam, ['setup_status' => WebhookEndpoint::SETUP_STATUS_MISSING_PERMISSIONS]);

        $this->actingAs($user)->putJson(route('integrations.update', [
            'current_team' => $user->currentTeam->slug, 'integration' => $integration->id,
        ]), ['credentials' => 'sk-with-write-scopes'])->assertOk();

        Bus::assertDispatched(ProvisionSamsaraWebhookJob::class, fn (ProvisionSamsaraWebhookJob $job) => $job->integrationId === $integration->id);
    }

    public function test_renaming_does_not_retry_the_setup(): void
    {
        Bus::fake([ProvisionSamsaraWebhookJob::class]);
        $user = User::factory()->create();
        $integration = $this->integration($user->currentTeam, ['setup_status' => WebhookEndpoint::SETUP_STATUS_MISSING_PERMISSIONS]);

        $this->actingAs($user)->putJson(route('integrations.update', [
            'current_team' => $user->currentTeam->slug, 'integration' => $integration->id,
        ]), ['name' => 'Samsara principal'])->assertOk();

        Bus::assertNotDispatched(ProvisionSamsaraWebhookJob::class);
    }
}
