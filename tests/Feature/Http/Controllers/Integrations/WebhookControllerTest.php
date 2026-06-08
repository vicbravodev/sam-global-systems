<?php

namespace Tests\Feature\Http\Controllers\Integrations;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\WebhookReceived;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveEndpoint(string $url): WebhookEndpoint
    {
        $provider = IntegrationProvider::factory()->samsara()->create();

        $team = Team::factory()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Webhook Ctrl Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'secret',
            'status' => TenantIntegrationStatus::Active,
        ]);

        return WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
            'url' => $url,
            'status' => 'active',
        ]);
    }

    public function test_it_accepts_and_persists_a_webhook_for_an_active_endpoint(): void
    {
        Queue::fake();
        Event::fake([WebhookReceived::class]);

        $endpoint = $this->createActiveEndpoint('ctrl-'.bin2hex(random_bytes(6)));

        $response = $this->postJson("/api/webhooks/{$endpoint->url}", [
            'event_type' => 'vehicle.updated',
            'data' => ['id' => 42],
        ]);

        $response->assertStatus(202);
        $response->assertJson(['status' => 'received']);

        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $endpoint->tenantIntegration->team_id,
            'event_type' => 'vehicle.updated',
        ]);

        Queue::assertPushed(ProcessWebhookEventJob::class);
        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_it_captures_signature_headers_and_raw_body(): void
    {
        Queue::fake();
        Event::fake([WebhookReceived::class]);

        $endpoint = $this->createActiveEndpoint('ctrl-'.bin2hex(random_bytes(6)));

        $body = ['event_type' => 'AlertIncident', 'data' => ['id' => 42]];
        $rawPayload = json_encode($body);
        $timestamp = (string) now()->getTimestampMs();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret);

        $response = $this->postJson("/api/webhooks/{$endpoint->url}", $body, [
            'X-Samsara-Signature' => $signature,
            'X-Samsara-Timestamp' => $timestamp,
        ]);

        $response->assertStatus(202);

        $event = WebhookEvent::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertSame($signature, $event->signature);
        $this->assertSame($timestamp, $event->signature_timestamp);
        $this->assertSame(
            $rawPayload,
            $event->raw_payload,
            'The controller must persist the exact raw request body for byte-for-byte HMAC verification',
        );
    }

    public function test_it_defaults_event_type_to_unknown_when_missing(): void
    {
        Queue::fake();
        Event::fake([WebhookReceived::class]);

        $endpoint = $this->createActiveEndpoint('ctrl-'.bin2hex(random_bytes(6)));

        $response = $this->postJson("/api/webhooks/{$endpoint->url}", [
            'data' => ['any' => 'value'],
        ]);

        $response->assertStatus(202);

        $this->assertDatabaseHas('webhook_events', [
            'team_id' => $endpoint->tenantIntegration->team_id,
            'event_type' => 'unknown',
        ]);
    }

    public function test_it_returns_404_when_endpoint_url_is_unknown(): void
    {
        $response = $this->postJson('/api/webhooks/does-not-exist', [
            'event_type' => 'vehicle.updated',
        ]);

        $response->assertNotFound();
    }

    public function test_it_returns_404_when_endpoint_is_inactive(): void
    {
        $provider = IntegrationProvider::factory()->samsara()->create();
        $team = Team::factory()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Inactive Endpoint Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'secret',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $endpoint = WebhookEndpoint::factory()->inactive()->create([
            'tenant_integration_id' => $integration->id,
            'url' => 'ctrl-inactive-'.bin2hex(random_bytes(4)),
        ]);

        $response = $this->postJson("/api/webhooks/{$endpoint->url}", [
            'event_type' => 'vehicle.updated',
        ]);

        $response->assertNotFound();
    }
}
