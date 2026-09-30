<?php

namespace Tests\Feature\Domains\Integrations;

use App\Contracts\RawEventIngestion;
use App\Domains\Integrations\Actions\HandleWebhook;
use App\Domains\Integrations\Actions\ValidateWebhookSignature;
use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * La salud del webhook se mide por firma: recibir algo no prueba que llegue
 * bien. Un endpoint sin la Secret Key de Samsara rechaza todo (fail-closed).
 */
class WebhookSignatureHealthTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private const string SECRET = 'c2Ftc2FyYS1zZWNyZXQta2V5LWZvci10ZXN0cw==';

    private function endpoint(?string $secret = self::SECRET): WebhookEndpoint
    {
        $team = User::factory()->create()->currentTeam;
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
        ]);

        return WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
            'secret' => $secret,
            'secret_configured_at' => $secret === null ? null : now(),
        ]);
    }

    private function signedEvent(WebhookEndpoint $endpoint, string $signingSecret): WebhookEvent
    {
        $body = ['eventType' => 'AlertIncident', 'data' => ['id' => 42]];
        $raw = (string) json_encode($body);
        $timestamp = (string) now()->getTimestampMs();

        return WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'AlertIncident',
            'payload_json' => $body,
            'signature' => 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$raw, $signingSecret),
            'signature_timestamp' => $timestamp,
            'raw_payload' => $raw,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);
    }

    private function process(WebhookEvent $event, WebhookEndpoint $endpoint, ?RawEventIngestion $ingestion = null): void
    {
        (new ProcessWebhookEventJob($event, $endpoint))->handle(
            app(ValidateWebhookSignature::class),
            $ingestion ?? app(RawEventIngestion::class),
        );
    }

    public function test_receipt_does_not_mark_the_endpoint_as_alive_before_validating(): void
    {
        Queue::fake();
        $endpoint = $this->endpoint();

        app(HandleWebhook::class)->execute($endpoint, 'AlertIncident', ['x' => 1], '{"x":1}', 'v1=bad', (string) now()->getTimestampMs());

        $endpoint->refresh();
        $this->assertNull($endpoint->last_received_at);
        $this->assertNull($endpoint->last_valid_received_at);
    }

    public function test_a_webhook_signed_with_the_stored_secret_is_ingested_and_marks_the_valid_delivery(): void
    {
        $endpoint = $this->endpoint();
        $event = $this->signedEvent($endpoint, self::SECRET);

        $ingestion = Mockery::mock(RawEventIngestion::class);
        $ingestion->shouldReceive('ingest')->once();

        $this->process($event, $endpoint, $ingestion);

        $this->assertSame(WebhookEventStatus::Processed, $event->refresh()->status);
        $endpoint->refresh();
        $this->assertNotNull($endpoint->last_valid_received_at);
        $this->assertNotNull($endpoint->last_received_at);
        $this->assertNull($endpoint->last_rejected_at);
        $this->assertSame('ok', $endpoint->signatureHealth());
    }

    public function test_an_endpoint_without_secret_rejects_every_webhook_fail_closed(): void
    {
        $endpoint = $this->endpoint(secret: null);
        // Firmado con lo que sea: sin secret no hay nada contra qué validar.
        $event = $this->signedEvent($endpoint, '');

        $ingestion = Mockery::mock(RawEventIngestion::class);
        $ingestion->shouldNotReceive('ingest');

        $this->process($event, $endpoint, $ingestion);

        $this->assertSame(WebhookEventStatus::InvalidSignature, $event->refresh()->status);
        $this->assertNull($event->processed_at);

        $endpoint->refresh();
        $this->assertNull($endpoint->last_valid_received_at);
        $this->assertNotNull($endpoint->last_rejected_at);
        $this->assertSame('secret_not_configured', $endpoint->last_rejection_reason);
        $this->assertSame('pending_secret', $endpoint->signatureHealth());

        $this->assertSystemLogged('webhook.event.rejected', fn (array $c) => $c['reason'] === 'secret_not_configured'
            && $c['input']['webhook_event_id'] === $event->id);
        $this->assertSystemNotLogged('webhook.signature.verified');
        $this->assertSystemNotLogged('webhook.event.ingested');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_signature_validation_is_fail_closed_without_a_secret(): void
    {
        $endpoint = $this->endpoint(secret: null);
        $raw = '{"x":1}';

        // Aunque alguien firme con la cadena vacía, sin secret no se acepta nada.
        $this->assertFalse(app(ValidateWebhookSignature::class)->execute($endpoint, $raw, hash_hmac('sha256', $raw, '')));
        $this->assertSystemNotLogged('webhook.signature.verified');
    }

    public function test_an_invalid_signature_does_not_advance_the_valid_marker(): void
    {
        $endpoint = $this->endpoint();
        $validAt = now()->subHour()->startOfSecond();
        $endpoint->forceFill(['last_valid_received_at' => $validAt])->save();

        $this->process($this->signedEvent($endpoint, 'wrong-secret'), $endpoint);

        $endpoint->refresh();
        $this->assertTrue($endpoint->last_valid_received_at->equalTo($validAt));
        $this->assertNotNull($endpoint->last_rejected_at);
        $this->assertSame('invalid_signature', $endpoint->last_rejection_reason);
        $this->assertSame('rejecting', $endpoint->signatureHealth());
        $this->assertSystemLogged('webhook.event.rejected', fn (array $c) => $c['reason'] === 'invalid_signature');
    }

    public function test_a_valid_delivery_after_rejections_restores_health(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->forceFill(['last_rejected_at' => now()->subMinutes(10), 'last_rejection_reason' => 'invalid_signature'])->save();

        $ingestion = Mockery::mock(RawEventIngestion::class);
        $ingestion->shouldReceive('ingest')->once();

        $this->process($this->signedEvent($endpoint, self::SECRET), $endpoint, $ingestion);

        $this->assertSame('ok', $endpoint->refresh()->signatureHealth());
    }
}
