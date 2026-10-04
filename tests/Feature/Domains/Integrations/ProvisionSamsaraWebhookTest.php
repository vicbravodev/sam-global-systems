<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Actions\ProvisionSamsaraWebhook;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Jobs\ProvisionSamsaraWebhookJob;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Jobs\NormalizeEventJob;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * SAM da de alta su webhook y su alerta de pánico en la cuenta de Samsara del
 * cliente con su token (spec 2026-10-04, alertas 05).
 */
class ProvisionSamsaraWebhookTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private const string SECRET = 'c2FtLXNlY3JldC1rZXk=';

    private IntegrationProvider $samsara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.samsara.webhook_base_url' => 'https://sam.example.com']);
        $this->samsara = IntegrationProvider::factory()->samsara()->create();
    }

    private function integration(?Team $team = null): TenantIntegration
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => ($team ?? Team::factory()->create(['name' => 'Fletes del Norte']))->id,
            'provider_id' => $this->samsara->id,
            'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $integration->id, 'key' => 'api_token', 'value_encrypted' => 'sk-'.$integration->id]);
        WebhookEndpoint::factory()->create(['tenant_integration_id' => $integration->id, 'secret' => null, 'secret_configured_at' => null]);

        return $integration;
    }

    private function fakeSamsara(int $webhookStatus = 200, int $alertStatus = 200): void
    {
        Http::fake([
            'api.samsara.com/webhooks/*' => Http::response(null, 204),
            'api.samsara.com/webhooks' => $webhookStatus === 200
                ? Http::response(['id' => '23918', 'secretKey' => self::SECRET, 'name' => 'SAM', 'url' => 'x', 'version' => '2018-01-01'])
                : Http::response(['message' => 'nope', 'requestId' => 'r1'], $webhookStatus),
            'api.samsara.com/alerts/configurations' => $alertStatus === 200
                ? Http::response(['data' => ['id' => 'cfg-sam']])
                : Http::response(['message' => 'nope', 'requestId' => 'r2'], $alertStatus),
        ]);
    }

    public function test_it_creates_the_webhook_and_the_panic_alert_and_keeps_the_secret(): void
    {
        $this->fakeSamsara();
        $integration = $this->integration();
        $endpoint = $integration->webhookEndpoint()->firstOrFail();

        $result = $this->assertNoTenantLeak($integration->team_id, fn () => app(ProvisionSamsaraWebhook::class)->execute($integration));

        $this->assertSame(WebhookEndpoint::SETUP_STATUS_PROVISIONED, $result);
        $endpoint->refresh();
        $this->assertSame(self::SECRET, $endpoint->secret);
        $this->assertNotNull($endpoint->secret_configured_at);
        $this->assertSame(WebhookEndpoint::SETUP_AUTOMATIC, $endpoint->setup_mode);
        $this->assertSame('23918', $endpoint->provider_webhook_id);
        $this->assertSame('cfg-sam', $endpoint->provider_alert_configuration_id);
        $this->assertTrue($endpoint->isProvisioned());

        // La URL que Samsara recibe es la pública https de este endpoint.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.samsara.com/webhooks'
            && $r['url'] === 'https://sam.example.com/api/webhooks/'.$endpoint->url
            && $r['name'] === 'SAM – Fletes del Norte');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.samsara.com/alerts/configurations'
            && $r['name'] === ProvisionSamsaraWebhook::ALERT_NAME);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'integration.webhook.provisioned')->sole();
        $this->assertSame($integration->team_id, (int) $audit->team_id);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($audit->toArray()));

        $c = $this->assertSystemLogged('integrations.webhook.provisioned');
        $this->assertSame($integration->id, $c['input']['integration_id']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($this->systemLogEntries()));
    }

    public function test_a_token_without_write_permissions_leaves_the_manual_flow(): void
    {
        $this->fakeSamsara(webhookStatus: 403);
        $integration = $this->integration();

        $result = app(ProvisionSamsaraWebhook::class)->execute($integration);

        $this->assertSame(WebhookEndpoint::SETUP_STATUS_MISSING_PERMISSIONS, $result);
        $endpoint = $integration->webhookEndpoint()->firstOrFail();
        $this->assertFalse($endpoint->hasSecret());
        $this->assertSame(WebhookEndpoint::SETUP_MANUAL, $endpoint->setup_mode);
        $this->assertSame(WebhookEndpoint::SETUP_STATUS_MISSING_PERMISSIONS, $endpoint->setup_status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/alerts/configurations'));

        $c = $this->assertSystemLogged('integrations.webhook.provision_failed');
        $this->assertSame('missing_permissions', $c['reason']);
    }

    public function test_if_the_alert_fails_the_created_webhook_is_removed(): void
    {
        $this->fakeSamsara(alertStatus: 500);
        $integration = $this->integration();

        $result = app(ProvisionSamsaraWebhook::class)->execute($integration);

        $this->assertSame(WebhookEndpoint::SETUP_STATUS_FAILED, $result);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.samsara.com/webhooks/23918');
        $endpoint = $integration->webhookEndpoint()->firstOrFail();
        $this->assertFalse($endpoint->hasSecret());
        $this->assertNull($endpoint->provider_webhook_id);

        $c = $this->assertSystemLogged('integrations.webhook.provision_failed');
        $this->assertSame('provider_error', $c['reason']);
        $this->assertTrue($c['calc']['compensated']);
    }

    public function test_an_endpoint_already_receiving_from_a_manual_webhook_is_respected(): void
    {
        $this->fakeSamsara();
        $integration = $this->integration();
        $integration->webhookEndpoint()->firstOrFail()->forceFill([
            'secret' => 'manual-secret', 'last_valid_received_at' => now(),
        ])->save();

        $this->assertSame('skipped', app(ProvisionSamsaraWebhook::class)->execute($integration));
        Http::assertNothingSent();
        $this->assertSame('already_receiving', $this->assertSystemLogged('integrations.webhook.provision_skipped')['reason']);

        // El botón "Configurar automáticamente" sí migra a automático.
        $this->assertSame(WebhookEndpoint::SETUP_STATUS_PROVISIONED, app(ProvisionSamsaraWebhook::class)->execute($integration, force: true));
    }

    public function test_it_is_idempotent_once_provisioned(): void
    {
        $this->fakeSamsara();
        $integration = $this->integration();
        app(ProvisionSamsaraWebhook::class)->execute($integration);

        $this->assertSame('skipped', app(ProvisionSamsaraWebhook::class)->execute($integration, force: true));
        Http::assertSentCount(2);
    }

    public function test_without_a_public_https_url_nothing_is_created(): void
    {
        config(['services.samsara.webhook_base_url' => null, 'app.url' => 'http://localhost']);
        $this->fakeSamsara();

        $this->assertSame('skipped', app(ProvisionSamsaraWebhook::class)->execute($this->integration()));
        Http::assertNothingSent();
        $this->assertSame('public_url_not_https', $this->assertSystemLogged('integrations.webhook.provision_skipped')['reason']);
    }

    public function test_non_samsara_or_inactive_integrations_are_skipped(): void
    {
        $this->fakeSamsara();
        $inactive = $this->integration();
        $inactive->forceFill(['status' => 'inactive'])->save();

        $this->assertSame('skipped', app(ProvisionSamsaraWebhook::class)->execute($inactive));
        Http::assertNothingSent();
    }

    public function test_connecting_an_integration_queues_the_provisioning_for_that_integration(): void
    {
        Bus::fake([ProvisionSamsaraWebhookJob::class, SyncIntegrationJob::class]);
        $integration = $this->integration();

        IntegrationConnected::dispatch($integration->team_id, $integration->id, 'samsara');

        Bus::assertDispatched(ProvisionSamsaraWebhookJob::class, fn (ProvisionSamsaraWebhookJob $job) => $job->integrationId === $integration->id
            && $job->teamId === $integration->team_id);
    }

    public function test_the_job_never_provisions_an_integration_of_another_tenant(): void
    {
        $this->fakeSamsara();
        $integration = $this->integration();
        $other = Team::factory()->create();

        (new ProvisionSamsaraWebhookJob($other->id, $integration->id))->handle(app(ProvisionSamsaraWebhook::class));

        Http::assertNothingSent();
        $this->assertFalse($integration->webhookEndpoint()->firstOrFail()->hasSecret());
        $this->assertSame('team_mismatch', $this->assertSystemLogged('integrations.webhook.provision_skipped')['reason']);
    }

    public function test_the_same_panic_through_the_clients_alert_and_sams_alert_opens_one_incident(): void
    {
        $this->seed(NormalizationSeeder::class);
        $this->seed(IncidentsSeeder::class);
        Bus::fake([PollSafetyEventsJob::class]);
        $integration = $this->integration();
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $this->samsara->id, 'external_id' => '281474993505355']);

        // Un botón, dos configuraciones de pánico: la del cliente (llega por
        // el poll de respaldo) y la de SAM (llega por webhook).
        foreach (['cfg-cliente', 'cfg-sam'] as $configuration) {
            $raw = RawEvent::factory()->pendingProcessing()->create([
                'team_id' => $integration->team_id,
                'provider_id' => $this->samsara->id,
                'event_type_raw' => 'AlertIncident',
                'occurred_at' => '2026-10-04 10:00:00',
                'payload_json' => ['eventType' => 'AlertIncident', 'data' => [
                    'configurationId' => $configuration,
                    'happenedAtTime' => '2026-10-04T10:00:00Z',
                    'incidentUrl' => 'https://cloud.samsara.com/o/1/fleet/workflows/incidents/'.$configuration.'/1/281474993505355/1',
                    'conditions' => [['triggerId' => 1034, 'description' => 'Panic Button', 'details' => ['panicButton' => ['vehicle' => ['id' => '281474993505355']]]]],
                ]],
            ]);
            (new NormalizeEventJob($raw->id))->handle(app(NormalizeRawEvent::class));
        }

        $this->assertSame(2, NormalizedEvent::withoutGlobalScopes()->where('team_id', $integration->team_id)->count());
        $this->assertSame(1, Incident::withoutGlobalScopes()->where('team_id', $integration->team_id)->count());
    }
}
