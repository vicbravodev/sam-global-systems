<?php

namespace Tests\Feature\Domains\Integrations;

use App\Contracts\RawEventIngestion;
use App\Domains\Integrations\Actions\HandleWebhook;
use App\Domains\Integrations\Actions\ValidateWebhookSignature;
use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Events\WebhookReceived;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class WebhookProcessingTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private function createEndpointWithIntegration(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Webhook Test Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => 'active',
        ]);

        $endpoint = WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
        ]);

        return [$user, $team, $provider, $integration, $endpoint];
    }

    /**
     * Evento firmado como lo firma Samsara: HMAC sobre `v1:{timestamp}:{cuerpo}`
     * con el cuerpo crudo y las cabeceras capturadas al recibirlo.
     *
     * @param  array<string, mixed>  $body
     */
    private function signedEvent(WebhookEndpoint $endpoint, array $body, string $eventType, ?string $secret = null): WebhookEvent
    {
        $rawPayload = (string) json_encode($body);
        $timestamp = (string) now()->getTimestamp();

        return WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => $eventType,
            'payload_json' => $body,
            'signature' => 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $secret ?? $endpoint->secret),
            'signature_timestamp' => $timestamp,
            'raw_payload' => $rawPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);
    }

    public function test_it_persists_webhook_event_before_processing(): void
    {
        Queue::fake();
        Event::fake([WebhookReceived::class]);

        [, $team, $provider, , $endpoint] = $this->createEndpointWithIntegration();

        $handleWebhook = app(HandleWebhook::class);
        $webhookEvent = $handleWebhook->execute($endpoint, 'vehicle.updated', ['vin' => '1234']);

        $this->assertNotNull(
            $webhookEvent->id,
            'Webhook event should be persisted to the database immediately upon receipt',
        );

        $this->assertDatabaseHas('webhook_events', [
            'id' => $webhookEvent->id,
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'event_type' => 'vehicle.updated',
            'status' => WebhookEventStatus::Received->value,
        ]);

        $this->assertEquals(
            WebhookEventStatus::Received,
            $webhookEvent->status,
            'Webhook event status should be "received" immediately after persistence — not yet processing',
        );
    }

    public function test_it_rejects_webhook_with_invalid_signature(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $payload = ['event_type' => 'vehicle.updated', 'data' => ['id' => 1], 'signature' => 'invalid-sig'];

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'vehicle.updated',
            'payload_json' => $payload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $job = new ProcessWebhookEventJob($webhookEvent, $endpoint);
        $job->handle(
            app(ValidateWebhookSignature::class),
            app(RawEventIngestion::class),
        );

        $webhookEvent->refresh();

        $this->assertEquals(
            WebhookEventStatus::InvalidSignature,
            $webhookEvent->status,
            'Webhook event with invalid signature should be marked as invalid_signature',
        );
    }

    public function test_it_marks_event_as_invalid_signature_on_failure(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $payload = ['event_type' => 'alert.triggered', 'signature' => 'bad-hash'];

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'alert.triggered',
            'payload_json' => $payload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $job = new ProcessWebhookEventJob($webhookEvent, $endpoint);
        $job->handle(
            app(ValidateWebhookSignature::class),
            app(RawEventIngestion::class),
        );

        $webhookEvent->refresh();

        $this->assertEquals(
            WebhookEventStatus::InvalidSignature,
            $webhookEvent->status,
            'Event should be marked as invalid_signature when HMAC verification fails',
        );

        $this->assertNull(
            $webhookEvent->processed_at,
            'Event with invalid signature should NOT have a processed_at timestamp',
        );
    }

    public function test_it_processes_webhook_with_valid_samsara_header_signature(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $body = ['eventType' => 'AlertIncident', 'data' => ['id' => 42]];
        $rawPayload = json_encode($body);
        $timestamp = (string) now()->getTimestampMs();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret);

        // Persist the event exactly as the controller would: parsed body plus the
        // raw bytes and the X-Samsara-Signature / X-Samsara-Timestamp headers.
        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'AlertIncident',
            'payload_json' => $body,
            'signature' => $signature,
            'signature_timestamp' => $timestamp,
            'raw_payload' => $rawPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $mockIngestion = Mockery::mock(RawEventIngestion::class);
        $mockIngestion->shouldReceive('ingest')->once();
        $this->app->instance(RawEventIngestion::class, $mockIngestion);

        $job = new ProcessWebhookEventJob($webhookEvent, $endpoint);
        $job->handle(
            app(ValidateWebhookSignature::class),
            app(RawEventIngestion::class),
        );

        $webhookEvent->refresh();

        $this->assertEquals(
            WebhookEventStatus::Processed,
            $webhookEvent->status,
            'A webhook with a valid X-Samsara-Signature header should be processed',
        );
    }

    public function test_it_rejects_webhook_with_tampered_header_signature(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $body = ['eventType' => 'AlertIncident', 'data' => ['id' => 42]];
        $rawPayload = json_encode($body);
        $timestamp = (string) now()->getTimestampMs();

        // Signature computed with the wrong secret → must not validate.
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, 'wrong-secret');

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'AlertIncident',
            'payload_json' => $body,
            'signature' => $signature,
            'signature_timestamp' => $timestamp,
            'raw_payload' => $rawPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $job = new ProcessWebhookEventJob($webhookEvent, $endpoint);
        $job->handle(
            app(ValidateWebhookSignature::class),
            app(RawEventIngestion::class),
        );

        $webhookEvent->refresh();

        $this->assertEquals(
            WebhookEventStatus::InvalidSignature,
            $webhookEvent->status,
            'A webhook whose HMAC does not match the endpoint secret must be rejected',
        );
        $this->assertNull($webhookEvent->processed_at);
    }

    public function test_it_logs_ingested_webhook_with_raw_header_signature_mode(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $body = ['eventType' => 'AlertIncident', 'data' => ['id' => 42]];
        $rawPayload = json_encode($body);
        $timestamp = (string) now()->getTimestampMs();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret);

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'AlertIncident',
            'payload_json' => $body,
            'signature' => $signature,
            'signature_timestamp' => $timestamp,
            'raw_payload' => $rawPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $mockIngestion = Mockery::mock(RawEventIngestion::class);
        $mockIngestion->shouldReceive('ingest')->once();

        (new ProcessWebhookEventJob($webhookEvent, $endpoint))->handle(app(ValidateWebhookSignature::class), $mockIngestion);

        $this->assertSystemLogged('webhook.event.ingested', fn (array $c) => $c['input']['webhook_event_id'] === $webhookEvent->id
            && $c['input']['signature_mode'] === 'raw_header'
            && $c['input']['event_type'] === 'AlertIncident'
            && $c['input']['event_type_valid'] === true
            && $c['input']['provider_code'] === 'samsara'
            && $c['result']['provider_code_fallback'] === false);
        $this->assertSystemLogged('webhook.signature.verified');
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * El antiguo modo "firma dentro del cuerpo" no llevaba hora: aceptarlo
     * dejaba reenviar para siempre un cuerpo firmado. Sin cuerpo crudo no hay
     * nada que verificar y el evento se rechaza sin ingerirse.
     */
    public function test_an_event_without_raw_body_is_rejected_even_with_a_valid_body_signature(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $payload = ['event_type' => 'vehicle.updated', 'data' => ['id' => 42]];
        $payload['signature'] = hash_hmac('sha256', (string) json_encode(['event_type' => 'vehicle.updated', 'data' => ['id' => 42]]), $endpoint->secret);

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'vehicle.updated',
            'payload_json' => $payload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $mockIngestion = Mockery::mock(RawEventIngestion::class);
        $mockIngestion->shouldNotReceive('ingest');

        (new ProcessWebhookEventJob($webhookEvent, $endpoint))->handle(app(ValidateWebhookSignature::class), $mockIngestion);

        $this->assertSame(WebhookEventStatus::InvalidSignature, $webhookEvent->refresh()->status);
        $this->assertSystemLogged('webhook.event.rejected', fn (array $c) => $c['reason'] === 'invalid_signature'
            && $c['input']['signature_mode'] === 'missing_raw_body');
        $this->assertSystemNotLogged('webhook.event.ingested');
        $this->assertStringNotContainsString($payload['signature'], (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_logs_rejected_webhook_with_invalid_signature(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $webhookEvent = $this->signedEvent($endpoint, ['eventType' => 'alert.triggered'], 'alert.triggered', secret: 'wrong-secret');

        (new ProcessWebhookEventJob($webhookEvent, $endpoint))->handle(app(ValidateWebhookSignature::class), app(RawEventIngestion::class));

        $this->assertSystemLogged('webhook.event.rejected', fn (array $c) => $c['reason'] === 'invalid_signature'
            && $c['input']['webhook_event_id'] === $webhookEvent->id
            && $c['input']['signature_mode'] === 'raw_header'
            && $c['input']['event_type'] === 'alert.triggered'
            && $c['input']['event_type_valid'] === true);
        $this->assertSystemLogged('webhook.signature.rejected', fn (array $c) => $c['reason'] === 'hmac_mismatch');
        $this->assertSystemNotLogged('webhook.event.ingested');
        $this->assertStringNotContainsString((string) $webhookEvent->signature, (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_does_not_log_attacker_controlled_event_type_on_rejection(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        foreach (["x\ninjected", str_repeat('a', 200)] as $eventType) {
            $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
                'team_id' => $endpoint->tenantIntegration->team_id,
                'provider_id' => $endpoint->tenantIntegration->provider_id,
                'event_type' => $eventType,
                'payload_json' => ['signature' => 'bad-hash'],
                'received_at' => now(),
                'status' => WebhookEventStatus::Received,
            ]);

            (new ProcessWebhookEventJob($webhookEvent, $endpoint))->handle(app(ValidateWebhookSignature::class), app(RawEventIngestion::class));
        }

        $entries = $this->systemLogEntries('webhook.event.rejected');
        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertNull($entry['context']['input']['event_type'] ?? null);
            $this->assertFalse($entry['context']['input']['event_type_valid']);
        }

        $this->assertStringNotContainsString('injected', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_does_not_log_attacker_controlled_event_type_on_ingestion(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        // event_type resuelto antes de validar la firma: aunque no parezca un
        // código, nunca llega al log tal cual.
        $webhookEvent = $this->signedEvent($endpoint, ['data' => ['id' => 42]], "x\ninjected");

        $mockIngestion = Mockery::mock(RawEventIngestion::class);
        $mockIngestion->shouldReceive('ingest')->once();

        (new ProcessWebhookEventJob($webhookEvent, $endpoint))->handle(app(ValidateWebhookSignature::class), $mockIngestion);

        $c = $this->assertSystemLogged('webhook.event.ingested');
        $this->assertNull($c['input']['event_type']);
        $this->assertFalse($c['input']['event_type_valid']);
        $this->assertSame('samsara', $c['input']['provider_code']);
        $this->assertStringNotContainsString('injected', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_dispatches_process_webhook_event_job_on_receipt(): void
    {
        Queue::fake();
        Event::fake([WebhookReceived::class]);

        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $handleWebhook = app(HandleWebhook::class);
        $handleWebhook->execute($endpoint, 'driver.created', ['driver_id' => 'abc']);

        Queue::assertPushed(ProcessWebhookEventJob::class, function ($job) {
            return $job->queue === 'ingestion';
        });

        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_it_forwards_processed_webhook_to_ingestion(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $webhookEvent = $this->signedEvent($endpoint, ['event_type' => 'vehicle.updated', 'data' => ['id' => 42]], 'vehicle.updated');

        $mockIngestion = Mockery::mock(RawEventIngestion::class);
        $mockIngestion->shouldReceive('ingest')
            ->once()
            ->withArgs(function ($teamId, $source, $eventType, $payload) use ($endpoint) {
                return $teamId === $endpoint->tenantIntegration->team_id
                    && $source === $endpoint->tenantIntegration->provider->code
                    && $eventType === 'vehicle.updated';
            });

        $this->app->instance(RawEventIngestion::class, $mockIngestion);

        $job = new ProcessWebhookEventJob($webhookEvent, $endpoint);
        $job->handle(
            app(ValidateWebhookSignature::class),
            app(RawEventIngestion::class),
        );

        $webhookEvent->refresh();

        $this->assertEquals(
            WebhookEventStatus::Processed,
            $webhookEvent->status,
            'Webhook event should be marked as processed after successful ingestion forwarding',
        );

        $this->assertNotNull(
            $webhookEvent->processed_at,
            'Processed webhook event should have a processed_at timestamp',
        );
    }

    /**
     * La ventana anti-replay se mide contra la hora de RECEPCIÓN, no contra
     * cuándo lo procesa el worker: una cola atrasada 10 min (deploy, pico) ya
     * no convierte un pánico auténtico en "firma inválida".
     */
    public function test_a_webhook_processed_late_by_a_backed_up_queue_is_still_valid(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $body = ['eventType' => 'AlertIncident', 'data' => ['id' => 43]];
        $rawPayload = json_encode($body);
        $timestamp = (string) now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret);

        $webhookEvent = WebhookEvent::factory()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'AlertIncident',
            'payload_json' => $body,
            'signature' => $signature,
            'signature_timestamp' => $timestamp,
            'raw_payload' => $rawPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $mockIngestion = Mockery::mock(RawEventIngestion::class);
        $mockIngestion->shouldReceive('ingest')->once();
        $this->app->instance(RawEventIngestion::class, $mockIngestion);

        $this->travel(10)->minutes();

        (new ProcessWebhookEventJob($webhookEvent, $endpoint))->handle(
            app(ValidateWebhookSignature::class),
            app(RawEventIngestion::class),
        );

        $this->assertSame(WebhookEventStatus::Processed, $webhookEvent->fresh()->status);
    }

    public function test_a_signature_older_than_the_window_at_receipt_is_still_rejected(): void
    {
        [, , , , $endpoint] = $this->createEndpointWithIntegration();

        $body = ['eventType' => 'AlertIncident', 'data' => ['id' => 44]];
        $rawPayload = json_encode($body);
        $timestamp = (string) now()->subMinutes(10)->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret);

        $webhookEvent = WebhookEvent::factory()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'AlertIncident',
            'payload_json' => $body,
            'signature' => $signature,
            'signature_timestamp' => $timestamp,
            'raw_payload' => $rawPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        (new ProcessWebhookEventJob($webhookEvent, $endpoint))->handle(
            app(ValidateWebhookSignature::class),
            app(RawEventIngestion::class),
        );

        $this->assertSame(WebhookEventStatus::InvalidSignature, $webhookEvent->fresh()->status);
    }
}
