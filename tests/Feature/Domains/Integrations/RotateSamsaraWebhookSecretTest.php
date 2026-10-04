<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Actions\RotateSamsaraWebhookSecret;
use App\Domains\Integrations\Actions\ValidateWebhookSignature;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RotateSamsaraWebhookSecretTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const string OLD_SECRET = 'b2xkLXNlY3JldA==';

    private const string NEW_SECRET = 'bmV3LXNlY3JldA==';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.samsara.webhook_base_url' => 'https://sam.example.com']);
        $this->user = User::factory()->create();
    }

    private function provisioned(): TenantIntegration
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $this->user->currentTeam->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
            'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $integration->id, 'key' => 'api_token', 'value_encrypted' => 'sk-test']);
        WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
            'secret' => self::OLD_SECRET,
            'setup_mode' => WebhookEndpoint::SETUP_AUTOMATIC,
            'setup_status' => WebhookEndpoint::SETUP_STATUS_PROVISIONED,
            'provider_webhook_id' => 'wh-old',
            'provider_alert_configuration_id' => 'cfg-sam',
        ]);

        return $integration;
    }

    private function fakeSamsara(int $patchStatus = 200): void
    {
        Http::fake([
            'api.samsara.com/webhooks/*' => Http::response(null, 204),
            'api.samsara.com/webhooks' => Http::response(['id' => 'wh-new', 'secretKey' => self::NEW_SECRET]),
            'api.samsara.com/alerts/configurations' => Http::response(['data' => ['id' => 'cfg-sam']], $patchStatus),
        ]);
    }

    private function signed(WebhookEndpoint $endpoint, string $secret): bool
    {
        $body = '{"eventType":"AlertIncident"}';
        $timestamp = (string) now()->timestamp;
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$body, (string) base64_decode($secret, true));

        return app(ValidateWebhookSignature::class)->execute($endpoint->fresh() ?? $endpoint, $body, $signature, $timestamp);
    }

    public function test_rotation_moves_the_alert_to_a_new_webhook_and_keeps_the_old_key_for_a_grace(): void
    {
        $this->fakeSamsara();
        $integration = $this->provisioned();
        $endpoint = $integration->webhookEndpoint()->firstOrFail();

        $this->assertSame(RotateSamsaraWebhookSecret::ROTATED, app(RotateSamsaraWebhookSecret::class)->execute($integration, $this->user));

        $endpoint->refresh();
        $this->assertSame(self::NEW_SECRET, $endpoint->secret);
        $this->assertSame('wh-new', $endpoint->provider_webhook_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['id'] === 'cfg-sam'
            && $r['actions'][0]['actionParams']['webhooks']['webhookIds'] === ['wh-new']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.samsara.com/webhooks/wh-old');

        $this->assertTrue($this->signed($endpoint, self::NEW_SECRET));
        $this->assertTrue($this->signed($endpoint, self::OLD_SECRET));
        $this->assertSystemLogged('webhook.signature.previous_secret_used');

        $this->travel(WebhookEndpoint::ROTATION_GRACE_MINUTES + 1)->minutes();
        $this->assertFalse($this->signed($endpoint, self::OLD_SECRET));
        $this->assertTrue($this->signed($endpoint, self::NEW_SECRET));

        $this->assertTrue($this->assertSystemLogged('integrations.webhook.rotated')['result']['old_webhook_deleted']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString(self::NEW_SECRET, (string) json_encode($this->systemLogEntries()));
    }

    public function test_if_the_alert_cannot_be_repointed_the_old_webhook_and_key_stay(): void
    {
        $this->fakeSamsara(patchStatus: 500);
        $integration = $this->provisioned();

        $this->assertSame(RotateSamsaraWebhookSecret::FAILED, app(RotateSamsaraWebhookSecret::class)->execute($integration, $this->user));

        $endpoint = $integration->webhookEndpoint()->firstOrFail();
        $this->assertSame(self::OLD_SECRET, $endpoint->secret);
        $this->assertSame('wh-old', $endpoint->provider_webhook_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.samsara.com/webhooks/wh-new');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.samsara.com/webhooks/wh-old');
    }

    public function test_a_manual_webhook_cannot_be_rotated_by_sam(): void
    {
        Http::fake();
        $integration = $this->provisioned();
        $integration->webhookEndpoint()->firstOrFail()->forceFill(['setup_mode' => WebhookEndpoint::SETUP_MANUAL])->save();

        $this->assertSame(RotateSamsaraWebhookSecret::SKIPPED, app(RotateSamsaraWebhookSecret::class)->execute($integration, $this->user));
        Http::assertNothingSent();
    }

    public function test_a_rotated_endpoints_old_key_never_validates_another_endpoint(): void
    {
        $this->fakeSamsara();
        $integration = $this->provisioned();
        app(RotateSamsaraWebhookSecret::class)->execute($integration, $this->user);

        // Otro tenant, otro endpoint: su llave no es la vieja de A.
        $other = WebhookEndpoint::factory()->create(['secret' => 'b3RoZXItc2VjcmV0']);

        $this->assertFalse($this->signed($other, self::OLD_SECRET));
        $this->assertTrue($this->signed($integration->webhookEndpoint()->firstOrFail(), self::OLD_SECRET));
    }
}
