<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Jobs\SyncDriversFromProviderJob;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SyncDriversFromProviderJobTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private function makeSamsaraIntegration(): TenantIntegration
    {
        $user = User::factory()->create();
        // `code` is unique, so tests with two tenants share one provider row.
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'status' => 'active',
            'auth_type' => 'api_key',
        ]);

        IntegrationCredential::factory()->create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test',
        ]);

        return $integration->load('provider');
    }

    public function test_it_skips_drivers_claimed_by_another_tenant_and_finishes_the_batch(): void
    {
        $owner = $this->makeSamsaraIntegration();
        $ownedDriver = Driver::factory()->create([
            'team_id' => $owner->team_id,
            'full_name' => 'Tenant A Driver',
            'external_primary_id' => 'd-1',
        ]);
        DriverExternalReference::factory()->create([
            'driver_id' => $ownedDriver->id,
            'provider_id' => $owner->provider_id,
            'external_id' => 'd-1',
        ]);

        $intruder = $this->makeSamsaraIntegration();

        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response([
                'data' => [],
                'pagination' => ['hasNextPage' => false],
            ], 200),
            'api.samsara.com/fleet/drivers*' => Http::response([
                'data' => [
                    ['id' => 'd-1', 'name' => 'Hijacked Driver'],
                    ['id' => 'd-2', 'name' => 'Tenant B Driver'],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        app()->call([new SyncDriversFromProviderJob($intruder), 'handle']);

        $this->assertSame(
            'Tenant A Driver',
            $ownedDriver->fresh()->full_name,
            'A sync run by another tenant must not write over the owning tenant\'s driver',
        );

        $intruderDrivers = Driver::withoutGlobalScopes()->where('team_id', $intruder->team_id)->get();

        $this->assertCount(1, $intruderDrivers, 'No orphan driver is left behind for the colliding id');
        $this->assertSame('d-2', $intruderDrivers->first()->external_primary_id);
        $this->assertSame(
            $ownedDriver->id,
            DriverExternalReference::query()->where('external_id', 'd-1')->sole()->driver_id,
        );

        $ctx = $this->assertSystemLogged('drivers.sync.completed');
        $this->assertSame(['team_id' => $intruder->team_id, 'integration_id' => $intruder->id], $ctx['input']);
        $this->assertSame(['received' => 2, 'synced' => 1, 'external_id_conflicts' => 1], $ctx['result']);
        $this->assertStringNotContainsString('Tenant B Driver', (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }
}
