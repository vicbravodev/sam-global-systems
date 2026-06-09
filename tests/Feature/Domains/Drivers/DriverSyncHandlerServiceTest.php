<?php

namespace Tests\Feature\Domains\Drivers;

use App\Contracts\DriverSyncHandler;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Services\DriverSyncHandlerService;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverSyncHandlerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_real_driver_sync_handler_is_bound(): void
    {
        $this->assertInstanceOf(DriverSyncHandlerService::class, app(DriverSyncHandler::class));
    }

    public function test_it_creates_a_driver_and_external_reference_from_integration_data(): void
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'x',
        ]);

        app(DriverSyncHandler::class)->syncFromIntegration(
            $integration->team_id,
            $integration->id,
            ['external_id' => 'drv-1', 'first_name' => 'Jane', 'last_name' => 'Doe'],
        );

        $driver = Driver::withoutGlobalScopes()->where('external_primary_id', 'drv-1')->first();

        $this->assertNotNull($driver);
        $this->assertSame('Jane Doe', $driver->full_name);
        $this->assertSame($integration->team_id, $driver->team_id);

        $this->assertDatabaseHas((new DriverExternalReference)->getTable(), [
            'driver_id' => $driver->id,
            'provider_id' => $provider->id,
            'external_id' => 'drv-1',
        ]);
    }
}
