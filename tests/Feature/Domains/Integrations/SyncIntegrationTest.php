<?php

namespace Tests\Feature\Domains\Integrations;

use App\Contracts\AssetSyncHandler;
use App\Domains\Integrations\Actions\SyncIntegration;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Events\IntegrationSyncCompleted;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class SyncIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createIntegrationWithSyncJob(SyncType $syncType = SyncType::Full): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Sync Test',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => 'active',
        ]);

        $syncJob = IntegrationSyncJob::create([
            'tenant_integration_id' => $integration->id,
            'type' => $syncType,
            'status' => SyncStatus::Pending,
        ]);

        return [$user, $team, $integration, $syncJob];
    }

    public function test_it_creates_sync_job_record_on_start(): void
    {
        Event::fake([IntegrationSyncCompleted::class]);

        [, , $integration, $syncJob] = $this->createIntegrationWithSyncJob();

        $mockAdapter = Mockery::mock(ProviderAdapter::class);
        $mockAdapter->shouldReceive('sync')
            ->once()
            ->andReturn(['assets' => [], 'drivers' => [], 'events' => [], 'records_processed' => 0]);

        $this->app->instance(ProviderAdapter::class, $mockAdapter);

        $action = app(SyncIntegration::class);
        $action->execute($integration, $syncJob);

        $syncJob->refresh();

        $this->assertNotNull(
            $syncJob->started_at,
            'Sync job should have a started_at timestamp after execution begins',
        );

        $this->assertEquals(
            SyncStatus::Completed,
            $syncJob->status,
            'Sync job should have completed status after successful execution',
        );
    }

    public function test_it_completes_sync_and_records_processed_count(): void
    {
        Event::fake([IntegrationSyncCompleted::class]);

        [, , $integration, $syncJob] = $this->createIntegrationWithSyncJob();

        $mockAdapter = Mockery::mock(ProviderAdapter::class);
        $mockAdapter->shouldReceive('sync')
            ->once()
            ->andReturn([
                'assets' => [['external_id' => 'v1'], ['external_id' => 'v2']],
                'drivers' => [['external_id' => 'd1']],
                'events' => [],
                'records_processed' => 42,
            ]);

        $this->app->instance(ProviderAdapter::class, $mockAdapter);

        $mockAssetSync = Mockery::mock(AssetSyncHandler::class);
        $mockAssetSync->shouldReceive('syncFromIntegration')->twice();
        $this->app->instance(AssetSyncHandler::class, $mockAssetSync);

        $action = app(SyncIntegration::class);
        $action->execute($integration, $syncJob);

        $syncJob->refresh();

        $this->assertEquals(
            42,
            $syncJob->records_processed,
            'Sync job should record the exact number of processed records returned by the adapter',
        );

        $this->assertNotNull(
            $syncJob->finished_at,
            'Sync job should have a finished_at timestamp after completion',
        );

        $integration->refresh();

        $this->assertNotNull(
            $integration->last_sync_at,
            'Integration last_sync_at should be updated after a successful sync',
        );

        Event::assertDispatched(IntegrationSyncCompleted::class, function ($event) use ($syncJob) {
            return $event->syncJobId === $syncJob->id && $event->recordsProcessed === 42;
        });
    }

    public function test_it_marks_sync_as_failed_on_provider_error(): void
    {
        [, , $integration, $syncJob] = $this->createIntegrationWithSyncJob();

        $mockAdapter = Mockery::mock(ProviderAdapter::class);
        $mockAdapter->shouldReceive('sync')
            ->once()
            ->andThrow(new \RuntimeException('Provider API returned 503'));

        $this->app->instance(ProviderAdapter::class, $mockAdapter);

        $action = app(SyncIntegration::class);

        try {
            $action->execute($integration, $syncJob);
        } catch (\RuntimeException) {
            // Expected exception — the action re-throws after marking failed
        }

        $syncJob->refresh();

        $this->assertEquals(
            SyncStatus::Failed,
            $syncJob->status,
            'Sync job should be marked as failed when the provider throws an exception',
        );

        $this->assertEquals(
            'Provider API returned 503',
            $syncJob->error_message,
            'Sync job error message should contain the exception message from the provider',
        );

        $integration->refresh();

        $this->assertNotNull(
            $integration->last_error_at,
            'Integration last_error_at should be set after a failed sync',
        );

        $this->assertEquals(
            'Provider API returned 503',
            $integration->last_error_message,
            'Integration last_error_message should reflect the sync failure',
        );
    }

    public function test_it_retries_sync_with_exponential_backoff(): void
    {
        $job = new SyncIntegrationJob(
            TenantIntegration::factory()->make(),
            IntegrationSyncJob::factory()->make(),
        );

        $this->assertEquals(
            3,
            $job->tries,
            'SyncIntegrationJob should be configured for 3 retries',
        );

        $this->assertEquals(
            [60, 300, 900],
            $job->backoff,
            'SyncIntegrationJob should use exponential backoff of [60, 300, 900] seconds',
        );

        $this->assertEquals(
            'sync',
            $job->queue,
            'SyncIntegrationJob should be dispatched to the sync queue',
        );
    }

    public function test_it_handles_duplicate_data_idempotently(): void
    {
        Event::fake([IntegrationSyncCompleted::class]);

        [, , $integration, $firstSyncJob] = $this->createIntegrationWithSyncJob();

        $syncResult = [
            'assets' => [['external_id' => 'v1']],
            'drivers' => [],
            'events' => [],
            'records_processed' => 1,
        ];

        $mockAdapter = Mockery::mock(ProviderAdapter::class);
        $mockAdapter->shouldReceive('sync')->andReturn($syncResult);
        $this->app->instance(ProviderAdapter::class, $mockAdapter);

        $mockAssetSync = Mockery::mock(AssetSyncHandler::class);
        $mockAssetSync->shouldReceive('syncFromIntegration')->twice();
        $this->app->instance(AssetSyncHandler::class, $mockAssetSync);

        $action = app(SyncIntegration::class);
        $action->execute($integration, $firstSyncJob);

        $secondSyncJob = IntegrationSyncJob::create([
            'tenant_integration_id' => $integration->id,
            'type' => SyncType::Full,
            'status' => SyncStatus::Pending,
        ]);

        $action = app(SyncIntegration::class);
        $action->execute($integration, $secondSyncJob);

        $firstSyncJob->refresh();
        $secondSyncJob->refresh();

        $this->assertEquals(
            SyncStatus::Completed,
            $firstSyncJob->status,
            'First sync job should be completed',
        );

        $this->assertEquals(
            SyncStatus::Completed,
            $secondSyncJob->status,
            'Second sync with same data should also complete — idempotent behavior via contracts',
        );
    }
}
