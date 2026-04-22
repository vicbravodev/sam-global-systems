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
use Tests\TestCase;

class WebhookProcessingTest extends TestCase
{
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

        $validPayload = ['event_type' => 'vehicle.updated', 'data' => ['id' => 42]];
        $rawJson = json_encode($validPayload);
        $validSignature = hash_hmac('sha256', $rawJson, $endpoint->secret);
        $validPayload['signature'] = $validSignature;

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $endpoint->tenantIntegration->team_id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'vehicle.updated',
            'payload_json' => $validPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

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
}
