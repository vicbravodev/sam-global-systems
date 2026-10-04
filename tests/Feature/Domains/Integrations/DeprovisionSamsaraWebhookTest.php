<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Jobs\DeprovisionSamsaraWebhookJob;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class DeprovisionSamsaraWebhookTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function integration(User $user, bool $provisioned): TenantIntegration
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => IntegrationProvider::query()->firstWhere('code', 'samsara')?->id ?? IntegrationProvider::factory()->samsara()->create()->id,
            'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $integration->id, 'key' => 'api_token', 'value_encrypted' => 'sk-live-'.$integration->id]);
        WebhookEndpoint::factory()->create(array_merge(
            ['tenant_integration_id' => $integration->id],
            $provisioned ? [
                'setup_mode' => WebhookEndpoint::SETUP_AUTOMATIC,
                'setup_status' => WebhookEndpoint::SETUP_STATUS_PROVISIONED,
                'provider_webhook_id' => '23918',
                'provider_alert_configuration_id' => 'cfg-sam',
            ] : [],
        ));

        return $integration;
    }

    private function disconnect(User $user, TenantIntegration $integration): void
    {
        $this->actingAs($user)
            ->deleteJson(route('integrations.destroy', ['current_team' => $user->currentTeam->slug, 'integration' => $integration->id]))
            ->assertNoContent();
    }

    public function test_disconnecting_a_provisioned_integration_removes_its_alert_and_webhook_at_samsara(): void
    {
        Http::fake([
            'api.samsara.com/alerts/configurations*' => Http::response(null, 204),
            'api.samsara.com/webhooks/*' => Http::response(null, 204),
        ]);
        $user = User::factory()->create();
        $integration = $this->integration($user, provisioned: true);

        $this->disconnect($user, $integration);

        $this->assertDatabaseMissing('tenant_integrations', ['id' => $integration->id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.samsara.com/alerts/configurations?id=cfg-sam'
            && $r->hasHeader('Authorization', 'Bearer sk-live-'.$integration->id));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.samsara.com/webhooks/23918');
        $this->assertSystemLogged('integrations.webhook.deprovisioned');
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('sk-live-', (string) json_encode($this->systemLogEntries()));
    }

    public function test_the_job_carrying_the_token_is_encrypted_on_the_queue(): void
    {
        Bus::fake([DeprovisionSamsaraWebhookJob::class]);
        $user = User::factory()->create();
        $integration = $this->integration($user, provisioned: true);

        $this->disconnect($user, $integration);

        Bus::assertDispatched(DeprovisionSamsaraWebhookJob::class, fn (DeprovisionSamsaraWebhookJob $job) => $job instanceof ShouldBeEncrypted
            && $job->teamId === $user->currentTeam->id
            && $job->webhookId === '23918');
    }

    public function test_a_manual_integration_touches_nothing_at_samsara(): void
    {
        Http::fake();
        $user = User::factory()->create();

        $this->disconnect($user, $this->integration($user, provisioned: false));

        Http::assertNothingSent();
    }

    public function test_provider_failures_never_block_the_disconnection(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'forbidden'], 403)]);
        $user = User::factory()->create();
        $integration = $this->integration($user, provisioned: true);

        $this->disconnect($user, $integration);

        $this->assertDatabaseMissing('tenant_integrations', ['id' => $integration->id]);
        $c = $this->assertSystemLogged('integrations.webhook.deprovisioned');
        $this->assertSame('missing_permissions', $c['reason']);
    }

    public function test_another_tenants_integration_cannot_be_disconnected(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $integration = $this->integration($owner, provisioned: true);

        $this->actingAs($intruder)
            ->deleteJson(route('integrations.destroy', ['current_team' => $intruder->currentTeam->slug, 'integration' => $integration->id]))
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseHas('tenant_integrations', ['id' => $integration->id]);
    }
}
