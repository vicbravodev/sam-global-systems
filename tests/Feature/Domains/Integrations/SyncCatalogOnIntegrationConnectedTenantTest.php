<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Listeners\SyncCatalogOnIntegrationConnected;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El listener corre fuera de HTTP (sin usuario autenticado): el sync job del
 * catálogo debe crearse y despacharse dentro del tenant de la integración,
 * para que el `SyncIntegrationJob` viaje con su `TenantContext` y el scope de
 * tenant aplique en todo lo que haga. Regla: app/CLAUDE.md, aislamiento.
 */
class SyncCatalogOnIntegrationConnectedTenantTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use RefreshDatabase;

    /** @var list<int|null> */
    private array $tenantSeenOnCreate = [];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncIntegrationJob::class]);

        IntegrationSyncJob::creating(function (): void {
            $this->tenantSeenOnCreate[] = TenantContext::id();
        });
    }

    public function test_listener_without_auth_or_context_creates_the_sync_job_inside_the_integration_tenant(): void
    {
        $team = Team::factory()->create();
        $integration = TenantIntegration::factory()->create(['team_id' => $team->id]);

        $this->assertNull(TenantContext::id());

        app(SyncCatalogOnIntegrationConnected::class)->handle(
            new IntegrationConnected($team->id, $integration->id, 'samsara'),
        );

        $syncJob = IntegrationSyncJob::query()->sole();
        $this->assertSame($integration->id, $syncJob->tenant_integration_id);
        $this->assertSame($team->id, $syncJob->tenantIntegration->team_id);
        $this->assertSame(SyncType::Full, $syncJob->type);
        $this->assertSame(SyncStatus::Pending, $syncJob->status);

        $this->assertSame([$team->id], $this->tenantSeenOnCreate);
        $this->assertNull(TenantContext::id(), 'El listener debe restaurar el contexto al salir.');

        Bus::assertDispatched(
            SyncIntegrationJob::class,
            fn (SyncIntegrationJob $job) => $job->integration->is($integration) && $job->syncJob->is($syncJob),
        );

        $this->assertSystemLogged(
            'integrations.catalog_sync.queued',
            fn (array $c) => $c['input']['team_id'] === $team->id
                && $c['input']['integration_id'] === $integration->id
                && $c['result']['sync_job_id'] === $syncJob->id,
        );
        $this->assertNoSensitiveDataLogged();
    }

    public function test_listener_does_not_touch_other_tenants_when_running_for_its_own(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $integrationA = TenantIntegration::factory()->create(['team_id' => $teamA->id]);
        $integrationB = TenantIntegration::factory()->create(['team_id' => $teamB->id]);

        $this->assertNoTenantLeak($teamB, function () use ($teamB, $integrationB): void {
            app(SyncCatalogOnIntegrationConnected::class)->handle(
                new IntegrationConnected($teamB->id, $integrationB->id, 'samsara'),
            );
        });

        $this->assertSame(1, IntegrationSyncJob::query()->where('tenant_integration_id', $integrationB->id)->count());
        $this->assertSame(0, IntegrationSyncJob::query()->where('tenant_integration_id', $integrationA->id)->count());
        $this->assertSame([$teamB->id], $this->tenantSeenOnCreate);
    }

    public function test_event_whose_team_does_not_own_the_integration_is_skipped(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $integrationA = TenantIntegration::factory()->create(['team_id' => $teamA->id]);

        // Evento de B que apunta a la integración de A: no se sincroniza el
        // catálogo de A en nombre de B.
        $this->assertNoTenantLeak($teamB, function () use ($teamB, $integrationA): void {
            app(SyncCatalogOnIntegrationConnected::class)->handle(
                new IntegrationConnected($teamB->id, $integrationA->id, 'samsara'),
            );
        });

        $this->assertSame(0, IntegrationSyncJob::query()->count());
        Bus::assertNotDispatched(SyncIntegrationJob::class);

        $this->assertSystemLogged(
            'integrations.catalog_sync.skipped',
            fn (array $c) => $c['reason'] === 'team_mismatch'
                && $c['input']['team_id'] === $teamB->id
                && $c['input']['integration_id'] === $integrationA->id,
        );
        $this->assertNoSensitiveDataLogged();
    }

    public function test_missing_integration_is_skipped(): void
    {
        $team = Team::factory()->create();

        app(SyncCatalogOnIntegrationConnected::class)->handle(
            new IntegrationConnected($team->id, 999_999, 'samsara'),
        );

        $this->assertSame(0, IntegrationSyncJob::query()->count());
        Bus::assertNotDispatched(SyncIntegrationJob::class);

        $this->assertSystemLogged(
            'integrations.catalog_sync.skipped',
            fn (array $c) => $c['reason'] === 'integration_not_found'
                && $c['input']['integration_id'] === 999_999,
        );
    }
}
