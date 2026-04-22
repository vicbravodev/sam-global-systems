<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceStatus;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Events\RawEventReceived;
use App\Domains\Ingestion\Jobs\PollExternalProviderJob;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PollExternalProviderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_job_creates_raw_events_from_provider(): void
    {
        Event::fake([RawEventReceived::class]);
        Queue::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Polling Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-api-key',
            'status' => 'active',
        ]);

        $eventSource = EventSource::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'tenant_integration_id' => $integration->id,
            'source_type' => EventSourceType::Polling,
            'source_name' => 'polling',
            'status' => EventSourceStatus::Active,
        ]);

        $mockAdapter = Mockery::mock(ProviderAdapter::class);
        $mockAdapter->shouldReceive('sync')
            ->once()
            ->andReturn([
                'assets' => [],
                'drivers' => [],
                'events' => [
                    ['eventType' => 'AlertIncident', 'eventId' => 'poll-evt-1', 'data' => ['foo' => 'bar']],
                    ['eventType' => 'AlertIncident', 'eventId' => 'poll-evt-2', 'data' => ['baz' => 'qux']],
                ],
                'records_processed' => 2,
            ]);

        $this->app->instance(ProviderAdapter::class, $mockAdapter);

        $job = new PollExternalProviderJob($eventSource->id);
        $job->handle(
            app(ProviderAdapter::class),
            app(StoreRawEvent::class),
            app(QueueRawEventForProcessing::class),
        );

        $rawEventCount = RawEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->count();

        $this->assertEquals(
            2,
            $rawEventCount,
            'PollExternalProviderJob should create one RawEvent per event returned by the provider API',
        );

        $eventSource->refresh();
        $config = $eventSource->config_json;

        $this->assertArrayHasKey(
            'last_polled_at',
            $config,
            'Event source config_json should have last_polled_at updated after polling',
        );
    }

    public function test_poll_job_runs_on_sync_queue(): void
    {
        $job = new PollExternalProviderJob(1);

        $this->assertEquals(
            'sync',
            $job->queue,
            'PollExternalProviderJob should be dispatched to the "sync" queue',
        );
    }
}
