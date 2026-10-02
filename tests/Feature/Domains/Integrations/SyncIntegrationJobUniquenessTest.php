<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Jobs\SyncDueIntegrationsJob;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Listeners\SyncCatalogOnIntegrationConnected;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El alta de una integración (SyncCatalogOnIntegrationConnected) y el
 * scheduler (SyncDueIntegrationsJob) pueden pedir el sync a la vez: sólo uno
 * queda en vuelo por integración, sin filas de seguimiento huérfanas.
 */
class SyncIntegrationJobUniquenessTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncIntegrationJob::class]);
    }

    public function test_the_job_is_unique_per_integration_for_as_long_as_a_sync_can_last(): void
    {
        $integration = TenantIntegration::factory()->create();
        $job = new SyncIntegrationJob($integration, new IntegrationSyncJob);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame("sync-integration-{$integration->id}", $job->uniqueId());
        $this->assertSame(SyncDueIntegrationsJob::STALE_SYNC_MINUTES * 60, $job->uniqueFor);
    }

    public function test_a_second_request_while_one_is_in_flight_creates_no_row_and_dispatches_nothing(): void
    {
        $integration = TenantIntegration::factory()->create();

        $first = TenantContext::for($integration->team_id, fn () => SyncIntegrationJob::dispatchUnlessInFlight($integration, SyncType::Full));
        $second = TenantContext::for($integration->team_id, fn () => SyncIntegrationJob::dispatchUnlessInFlight($integration, SyncType::Incremental));

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, IntegrationSyncJob::query()->where('tenant_integration_id', $integration->id)->count());
        Bus::assertDispatchedTimes(SyncIntegrationJob::class, 1);
        Bus::assertDispatched(SyncIntegrationJob::class, fn (SyncIntegrationJob $job) => $job->syncJob->is($first));

        $c = $this->assertSystemLogged('integrations.sync.dispatch');
        $this->assertSame('skipped', $c['outcome']);
        $this->assertSame('sync_in_flight', $c['reason']);
        $this->assertSame($integration->id, $c['input']['integration_id']);
        $this->assertSame($integration->team_id, $c['input']['team_id']);
        $this->assertSame('incremental', $c['input']['type']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_connect_listener_does_not_stack_a_sync_on_top_of_the_schedulers(): void
    {
        $team = Team::factory()->create();
        $integration = TenantIntegration::factory()->active()->create(['team_id' => $team->id, 'last_sync_at' => null]);

        // El scheduler llegó primero.
        (new SyncDueIntegrationsJob)->handle();

        app(SyncCatalogOnIntegrationConnected::class)->handle(
            new IntegrationConnected($team->id, $integration->id, 'samsara'),
        );

        $this->assertSame(1, IntegrationSyncJob::query()->where('tenant_integration_id', $integration->id)->count());
        Bus::assertDispatchedTimes(SyncIntegrationJob::class, 1);
        $this->assertSystemLogged(
            'integrations.catalog_sync.requested',
            fn (array $c) => $c['outcome'] === 'skipped' && $c['reason'] === 'sync_in_flight' && $c['input']['integration_id'] === $integration->id,
        );
    }

    public function test_the_scheduler_does_not_stack_a_sync_when_the_lock_is_held_but_the_row_is_not_visible_yet(): void
    {
        $integration = TenantIntegration::factory()->active()->create(['last_sync_at' => null]);

        // El alta tomó el candado y todavía no ha escrito su fila: el chequeo
        // de filas en vuelo del scheduler no la ve, el candado sí.
        $lock = new UniqueLock(Cache::store());
        $this->assertTrue($lock->acquire(new SyncIntegrationJob($integration, new IntegrationSyncJob)));

        (new SyncDueIntegrationsJob)->handle();

        $this->assertSame(0, IntegrationSyncJob::query()->where('tenant_integration_id', $integration->id)->count());
        Bus::assertNotDispatched(SyncIntegrationJob::class);
    }

    public function test_an_in_flight_sync_of_another_tenants_integration_never_blocks_this_one(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $integrationA = TenantIntegration::factory()->create(['team_id' => $teamA->id]);
        $integrationB = TenantIntegration::factory()->create(['team_id' => $teamB->id]);

        TenantContext::for($teamB->id, fn () => SyncIntegrationJob::dispatchUnlessInFlight($integrationB, SyncType::Full));

        $syncJob = $this->assertNoTenantLeak($teamA, fn () => TenantContext::for(
            $teamA->id,
            fn () => SyncIntegrationJob::dispatchUnlessInFlight($integrationA, SyncType::Full),
        ));

        $this->assertNotNull($syncJob);
        $this->assertSame($integrationA->id, $syncJob->tenant_integration_id);
        Bus::assertDispatchedTimes(SyncIntegrationJob::class, 2);
    }
}
