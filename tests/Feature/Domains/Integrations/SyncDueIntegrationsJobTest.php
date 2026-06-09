<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Jobs\SyncDueIntegrationsJob;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SyncDueIntegrationsJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(array $attributes = []): TenantIntegration
    {
        return TenantIntegration::withoutGlobalScopes()->create(array_merge([
            'team_id' => Team::factory()->create()->id,
            'provider_id' => IntegrationProvider::factory()->create()->id,
            'name' => 'Integration',
            'status' => TenantIntegrationStatus::Active,
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'x',
        ], $attributes));
    }

    public function test_it_dispatches_incremental_sync_for_due_integrations_across_tenants(): void
    {
        Bus::fake([SyncIntegrationJob::class]);

        $dueA = $this->makeIntegration(['last_sync_at' => null]);
        $dueB = $this->makeIntegration(['last_sync_at' => now()->subHour()]);
        $this->makeIntegration(['last_sync_at' => now()]); // synced just now — not due
        $this->makeIntegration([
            'last_sync_at' => null,
            'status' => TenantIntegrationStatus::Inactive,
        ]);

        (new SyncDueIntegrationsJob)->handle();

        Bus::assertDispatchedTimes(SyncIntegrationJob::class, 2);

        foreach ([$dueA, $dueB] as $integration) {
            $this->assertDatabaseHas('integration_sync_jobs', [
                'tenant_integration_id' => $integration->id,
                'type' => SyncType::Incremental->value,
                'status' => SyncStatus::Pending->value,
            ]);
        }
    }

    public function test_it_skips_integrations_with_an_in_flight_sync(): void
    {
        Bus::fake([SyncIntegrationJob::class]);

        $integration = $this->makeIntegration(['last_sync_at' => null]);
        IntegrationSyncJob::create([
            'tenant_integration_id' => $integration->id,
            'type' => SyncType::Full,
            'status' => SyncStatus::Running,
        ]);

        (new SyncDueIntegrationsJob)->handle();

        Bus::assertNotDispatched(SyncIntegrationJob::class);
    }

    public function test_it_respects_a_per_integration_catalog_interval(): void
    {
        Bus::fake([SyncIntegrationJob::class]);

        // Synced 20 minutes ago but configured for a 60-minute interval — not due.
        $this->makeIntegration([
            'last_sync_at' => now()->subMinutes(20),
            'config_json' => ['sync' => ['catalog_interval_minutes' => 60]],
        ]);

        (new SyncDueIntegrationsJob)->handle();

        Bus::assertNotDispatched(SyncIntegrationJob::class);
    }
}
