<?php

namespace Tests\Feature\Domains\Integrations;

use App\Contracts\RawEventIngestion;
use App\Domains\Assets\Jobs\DispatchTelematicsFeedsJob;
use App\Domains\Assets\Jobs\FollowVehicleStatsFeedJob;
use App\Domains\Assets\Jobs\PollAllDeviceConnectivityJob;
use App\Domains\Assets\Jobs\PollAssetConnectivityJob;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Jobs\PollSamsaraSafetyEventsJob;
use App\Domains\Integrations\Actions\ValidateWebhookSignature;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Jobs\SyncDueIntegrationsJob;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Un tenant dado de baja (soft-delete) no debe seguir ingiriendo: ni por
 * webhook ni por los pollers del scheduler. La suspensión por billing no se
 * toca aquí (AuthorizeAction la aplica a permisos de usuario, no al pipeline).
 */
class DeletedTenantIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function integration(Team $team): TenantIntegration
    {
        $provider = IntegrationProvider::query()->where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        return TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'status' => TenantIntegrationStatus::Active,
            // The telematics feed only follows integrations with a catalog.
            'last_sync_at' => now()->subHour(),
        ]);
    }

    private function deletedTeam(): Team
    {
        $team = Team::factory()->create();
        $team->delete();

        return $team;
    }

    public function test_webhooks_for_a_deleted_tenant_are_rejected(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $endpoint = WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $this->integration($team)->id,
            'url' => 'deleted-'.bin2hex(random_bytes(6)),
            'status' => 'active',
        ]);
        $team->delete();

        $this->postJson("/api/webhooks/{$endpoint->url}", ['event_type' => 'x'])->assertNotFound();

        $this->assertSame(0, WebhookEvent::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_queued_webhook_events_of_a_deleted_tenant_are_not_ingested(): void
    {
        $team = Team::factory()->create();
        $endpoint = WebhookEndpoint::factory()->create([
            'tenant_integration_id' => $this->integration($team)->id,
            'status' => 'active',
        ]);
        $event = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $endpoint->tenantIntegration->provider_id,
            'event_type' => 'x',
            'payload_json' => [],
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);
        $team->delete();

        $ingestion = Mockery::mock(RawEventIngestion::class);
        $ingestion->shouldNotReceive('ingest');

        (new ProcessWebhookEventJob($event, $endpoint))->handle(app(ValidateWebhookSignature::class), $ingestion);

        $this->assertSame(WebhookEventStatus::Failed, $event->fresh()->status);
    }

    public function test_scheduled_pollers_skip_deleted_tenants(): void
    {
        Bus::fake();

        $live = $this->integration(Team::factory()->create());
        $this->integration($this->deletedTeam());

        (new SyncDueIntegrationsJob)->handle();
        (new PollSamsaraSafetyEventsJob)->handle();
        (new DispatchTelematicsFeedsJob)->handle();
        (new PollAllDeviceConnectivityJob)->handle();

        Bus::assertDispatchedTimes(SyncIntegrationJob::class, 1);
        Bus::assertDispatched(SyncIntegrationJob::class, fn (SyncIntegrationJob $job) => $job->integration->is($live));
        Bus::assertDispatchedTimes(PollSafetyEventsJob::class, 1);
        // One cycle per feed (motion + diagnostics), for the live tenant only.
        Bus::assertDispatchedTimes(FollowVehicleStatsFeedJob::class, 2);
        Bus::assertDispatched(FollowVehicleStatsFeedJob::class, fn (FollowVehicleStatsFeedJob $job) => $job->integration->is($live));
        Bus::assertDispatchedTimes(PollAssetConnectivityJob::class, 1);
    }
}
