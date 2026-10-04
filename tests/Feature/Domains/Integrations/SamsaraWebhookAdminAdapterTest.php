<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Las llamadas de escritura con las que SAM da de alta (y limpia) su webhook
 * y su alerta de pánico en la cuenta de Samsara del cliente.
 */
class SamsaraWebhookAdminAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function integration(?string $token = 'sk-test-token'): TenantIntegration
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => Team::factory()->create()->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
            'credentials_encrypted' => '',
        ]);

        if ($token !== null) {
            IntegrationCredential::create([
                'tenant_integration_id' => $integration->id,
                'key' => 'api_token',
                'value_encrypted' => $token,
            ]);
        }

        return $integration;
    }

    public function test_create_webhook_returns_its_id_and_secret_key(): void
    {
        Http::fake(['api.samsara.com/webhooks' => Http::response([
            'id' => '23918', 'name' => 'SAM', 'url' => 'https://sam.test/api/webhooks/x', 'secretKey' => 'c2VjcmV0', 'version' => '2018-01-01',
        ])]);

        $created = app(SamsaraAdapter::class)->createWebhook($this->integration(), 'SAM – Flota', 'https://sam.test/api/webhooks/x');

        $this->assertSame(['id' => '23918', 'secret' => 'c2VjcmV0'], $created);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.samsara.com/webhooks'
            && $r['name'] === 'SAM – Flota'
            && $r['url'] === 'https://sam.test/api/webhooks/x'
            && $r->hasHeader('Authorization', 'Bearer sk-test-token'));
    }

    public function test_create_webhook_without_secret_in_the_answer_fails(): void
    {
        Http::fake(['api.samsara.com/webhooks' => Http::response(['id' => '23918'])]);

        $this->expectException(ProviderRequestFailedException::class);

        app(SamsaraAdapter::class)->createWebhook($this->integration(), 'SAM', 'https://sam.test/x');
    }

    public function test_a_token_without_write_scope_surfaces_the_status(): void
    {
        Http::fake(['api.samsara.com/webhooks' => Http::response(['message' => 'Unauthorized', 'requestId' => 'r1'], 403)]);

        try {
            app(SamsaraAdapter::class)->createWebhook($this->integration(), 'SAM', 'https://sam.test/x');
            $this->fail('Debió lanzar.');
        } catch (ProviderRequestFailedException $e) {
            $this->assertSame(403, $e->status);
            $this->assertTrue($e->isUnauthorized());
        }
    }

    public function test_without_a_token_nothing_is_sent(): void
    {
        Http::fake();

        try {
            app(SamsaraAdapter::class)->createWebhook($this->integration(token: null), 'SAM', 'https://sam.test/x');
            $this->fail('Debió lanzar.');
        } catch (ProviderRequestFailedException $e) {
            $this->assertTrue($e->isUnauthorized());
        }

        Http::assertNothingSent();
    }

    public function test_create_panic_alert_sends_the_panic_trigger_and_the_webhook_action(): void
    {
        Http::fake(['api.samsara.com/alerts/configurations' => Http::response(['data' => ['id' => 'cfg-sam']])]);

        $id = app(SamsaraAdapter::class)->createPanicAlertConfiguration($this->integration(), 'SAM – Botón de pánico', '23918');

        $this->assertSame('cfg-sam', $id);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['name'] === 'SAM – Botón de pánico'
            && $r['isEnabled'] === true
            && $r['scope'] === ['all' => true]
            && $r['triggers'] === [['triggerTypeId' => 1034]]
            && $r['actions'] === [['actionTypeId' => 4, 'actionParams' => ['webhooks' => ['webhookIds' => ['23918'], 'payloadType' => 'enriched']]]]);
    }

    public function test_point_alert_to_another_webhook_patches_only_its_action(): void
    {
        Http::fake(['api.samsara.com/alerts/configurations' => Http::response(['data' => ['id' => 'cfg-sam']])]);

        app(SamsaraAdapter::class)->pointAlertConfigurationToWebhook($this->integration(), 'cfg-sam', '99999');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r['id'] === 'cfg-sam'
            && $r['actions'] === [['actionTypeId' => 4, 'actionParams' => ['webhooks' => ['webhookIds' => ['99999'], 'payloadType' => 'enriched']]]]);
    }

    public function test_deletes_treat_not_found_as_done(): void
    {
        Http::fake([
            'api.samsara.com/webhooks/23918' => Http::response(['message' => 'Object not found.'], 404),
            'api.samsara.com/alerts/configurations*' => Http::response(null, 204),
        ]);
        $adapter = app(SamsaraAdapter::class);
        $integration = $this->integration();

        $adapter->deleteWebhook($integration, '23918');
        $adapter->deleteAlertConfiguration($integration, 'cfg-sam');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.samsara.com/webhooks/23918');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.samsara.com/alerts/configurations?id=cfg-sam');
    }

    public function test_delete_failures_other_than_not_found_throw(): void
    {
        Http::fake(['api.samsara.com/webhooks/23918' => Http::response(['message' => 'boom'], 500)]);

        $this->expectException(ProviderRequestFailedException::class);

        app(SamsaraAdapter::class)->deleteWebhook($this->integration(), '23918');
    }
}
