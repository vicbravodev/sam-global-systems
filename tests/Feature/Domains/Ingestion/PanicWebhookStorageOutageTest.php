<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Concerns\FakesObjectStorageOutage;
use Tests\TestCase;

/**
 * Un pánico NUNCA se pierde ni se retrasa por RustFS/S3 caído: el camino
 * webhook → raw event → normalización → incidente vive en DB y no escribe en
 * el storage de objetos. Este test lo fija con el storage lanzando en cada
 * operación.
 */
class PanicWebhookStorageOutageTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use FakesObjectStorageOutage;
    use RefreshDatabase;

    private IntegrationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->fakeObjectStorage();
        $this->seed(IncidentsSeeder::class);

        $this->provider = IntegrationProvider::factory()->samsara()->create();

        $emergency = EventCategory::factory()->emergency()->create();
        $panic = EventType::factory()->create([
            'code' => 'panic_button',
            'category_id' => $emergency->id,
            'default_severity_id' => EventSeverity::factory()->critical()->create()->id,
        ]);

        EventMappingRule::factory()->create([
            'provider_id' => $this->provider->id,
            'external_event_type' => 'PanicButton',
            'mapped_event_type_id' => $panic->id,
        ]);
    }

    public function test_panic_webhook_opens_an_incident_while_object_storage_is_down(): void
    {
        [$team, $endpoint] = $this->tenantWithEndpoint();
        [$otherTeam] = $this->tenantWithEndpoint();
        $this->objectStorageGoesDown();

        $this->assertNoTenantLeak($team, function () use ($endpoint): void {
            $this->postPanic($endpoint, 'evt-panic')->assertStatus(202);
        });

        $rawEvent = RawEvent::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $this->assertSame(RawEventStatus::Processed, $rawEvent->status);
        $this->assertSame(1, NormalizedEvent::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, Incident::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(0, Incident::withoutGlobalScopes()->where('team_id', $otherTeam->id)->count());

        $this->assertNoSensitiveDataLogged();
    }

    /**
     * @return array{0: Team, 1: WebhookEndpoint}
     */
    private function tenantWithEndpoint(): array
    {
        $team = User::factory()->create()->currentTeam;

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $this->provider->id,
            'name' => 'Samsara',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $asset = Asset::factory()->create(['team_id' => $team->id]);
        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $this->provider->id,
            'external_id' => "vehicle-{$team->id}",
        ]);

        $endpoint = WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $integration->id,
            'url' => 's3-outage-'.bin2hex(random_bytes(4)),
            'status' => 'active',
        ]);

        return [$team, $endpoint];
    }

    private function postPanic(WebhookEndpoint $endpoint, string $eventId): TestResponse
    {
        $teamId = $endpoint->tenantIntegration->team_id;
        $body = [
            'eventId' => $eventId,
            'eventType' => 'PanicButton',
            'eventTime' => now()->toIso8601String(),
            'asset' => ['id' => "vehicle-{$teamId}"],
        ];
        $rawPayload = (string) json_encode($body);
        $timestamp = (string) now()->getTimestampMs();

        return $this->call('POST', "/api/webhooks/{$endpoint->url}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SAMSARA_SIGNATURE' => 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$rawPayload, $endpoint->secret),
            'HTTP_X_SAMSARA_TIMESTAMP' => $timestamp,
        ], $rawPayload);
    }
}
