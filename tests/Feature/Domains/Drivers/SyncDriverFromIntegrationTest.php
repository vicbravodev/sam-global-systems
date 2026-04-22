<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Actions\SyncDriverFromIntegration;
use App\Domains\Drivers\Events\DriverDiscovered;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SyncDriverFromIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function createSetup(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();
        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Test Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'test-key',
            'status' => 'active',
        ]);

        return [$user, $team, $provider, $integration];
    }

    public function test_it_creates_driver_from_integration_sync(): void
    {
        Event::fake([DriverDiscovered::class]);

        [$user, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncDriverFromIntegration::class);

        $driver = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-driver-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'employee_code' => 'EMP-1234',
        ]);

        $this->assertNotNull(
            $driver,
            'SyncDriverFromIntegration should return a Driver instance for new driver data',
        );

        $this->assertDatabaseHas('drivers', [
            'team_id' => $team->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'full_name' => 'John Doe',
            'employee_code' => 'EMP-1234',
        ]);

        $this->assertDatabaseHas('driver_external_references', [
            'driver_id' => $driver->id,
            'provider_id' => $provider->id,
            'external_id' => 'ext-driver-001',
        ]);
    }

    public function test_it_updates_existing_driver_on_duplicate_external_id(): void
    {
        Event::fake([DriverDiscovered::class]);

        [$user, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncDriverFromIntegration::class);

        $originalDriver = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-driver-002',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $updatedDriver = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-driver-002',
            'first_name' => 'Janet',
            'last_name' => 'Smith',
            'employee_code' => 'EMP-5678',
        ]);

        $this->assertEquals(
            $originalDriver->id,
            $updatedDriver->id,
            'Syncing an existing external_id should update the existing driver, not create a new one',
        );

        $this->assertEquals(
            'Janet',
            $updatedDriver->first_name,
            'Driver first_name should be updated after re-sync with new data',
        );

        $this->assertEquals(
            1,
            Driver::withoutGlobalScopes()->where('team_id', $team->id)->count(),
            'Only one driver should exist after syncing the same external_id twice',
        );
    }

    public function test_it_dispatches_driver_discovered_event_for_new_driver(): void
    {
        Event::fake([DriverDiscovered::class]);

        [$user, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncDriverFromIntegration::class);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-driver-003',
            'first_name' => 'Bob',
            'last_name' => 'Builder',
        ]);

        Event::assertDispatched(DriverDiscovered::class, function ($event) use ($team) {
            return $event->teamId === $team->id
                && $event->fullName === 'Bob Builder'
                && $event->externalId === 'ext-driver-003';
        });
    }

    public function test_it_does_not_dispatch_driver_discovered_event_for_existing_driver(): void
    {
        [$user, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncDriverFromIntegration::class);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-driver-004',
            'first_name' => 'Alice',
            'last_name' => 'Wonder',
        ]);

        Event::fake([DriverDiscovered::class]);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-driver-004',
            'first_name' => 'Alice',
            'last_name' => 'Wonderland',
        ]);

        Event::assertNotDispatched(
            DriverDiscovered::class,
            'DriverDiscovered should not be dispatched when updating an existing driver',
        );
    }

    public function test_it_sets_full_name_from_first_and_last_name(): void
    {
        Event::fake([DriverDiscovered::class]);

        [$user, $team, $provider, $integration] = $this->createSetup();

        $action = app(SyncDriverFromIntegration::class);

        $driver = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-driver-005',
            'first_name' => 'María',
            'last_name' => 'García',
        ]);

        $this->assertEquals(
            'María García',
            $driver->full_name,
            'full_name should be derived as "first_name last_name"',
        );
    }
}
