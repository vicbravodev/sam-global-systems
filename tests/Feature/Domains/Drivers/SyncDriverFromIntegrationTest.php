<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Drivers\Actions\SyncDriverFromIntegration;
use App\Domains\Drivers\Events\DriverDiscovered;
use App\Domains\Drivers\Exceptions\DriverExternalReferenceConflictException;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use App\Support\TenantContext;
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

    public function test_sync_persists_normalized_e164_phone_on_create(): void
    {
        Event::fake([DriverDiscovered::class]);

        [$user, $team, $provider, $integration] = $this->createSetup();

        $driver = app(SyncDriverFromIntegration::class)->execute($team->id, $integration->id, [
            'external_id' => 'ext-phone-1',
            'name' => 'Jane Doe',
            'phone' => '+1 (415) 555-1234',
        ]);

        $this->assertSame('+14155551234', $driver->fresh()->phone);
    }

    public function test_sync_stores_null_when_phone_is_not_e164(): void
    {
        Event::fake([DriverDiscovered::class]);

        [$user, $team, $provider, $integration] = $this->createSetup();

        $driver = app(SyncDriverFromIntegration::class)->execute($team->id, $integration->id, [
            'external_id' => 'ext-phone-2',
            'name' => 'Jane Doe',
            'phone' => '415-555-1234',
        ]);

        $this->assertNull($driver->fresh()->phone);
    }

    public function test_sync_preserves_existing_phone_when_absent_from_payload(): void
    {
        Event::fake([DriverDiscovered::class]);

        [$user, $team, $provider, $integration] = $this->createSetup();

        $driver = app(SyncDriverFromIntegration::class)->execute($team->id, $integration->id, [
            'external_id' => 'ext-phone-3', 'name' => 'Jane', 'phone' => '+14155551234',
        ]);
        $this->assertSame('+14155551234', $driver->fresh()->phone);

        app(SyncDriverFromIntegration::class)->execute($team->id, $integration->id, [
            'external_id' => 'ext-phone-3', 'name' => 'Jane Roe',
        ]);
        $this->assertSame('+14155551234', $driver->fresh()->phone);
    }

    /**
     * Regresión: el re-sync filtraba con array_filter() sin callback, que
     * descartaba los valores "0" (código de empleado o apellido) aunque la
     * creación sí los guardaba; el driver se quedaba con el valor viejo.
     */
    public function test_resync_applies_zero_string_values_like_creation_does(): void
    {
        Event::fake([DriverDiscovered::class]);

        [, $team, , $integration] = $this->createSetup();
        $action = app(SyncDriverFromIntegration::class);

        $driver = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-zero', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'employee_code' => 'EMP-9',
        ]);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-zero', 'first_name' => 'Ana', 'last_name' => '0', 'employee_code' => '0',
        ]);

        $fresh = $driver->fresh();
        $this->assertSame('0', $fresh->employee_code);
        $this->assertSame('0', $fresh->last_name);
        $this->assertSame('Ana 0', $fresh->full_name);
    }

    public function test_resync_keeps_existing_values_when_payload_fields_are_absent_or_empty(): void
    {
        Event::fake([DriverDiscovered::class]);

        [, $team, , $integration] = $this->createSetup();
        $action = app(SyncDriverFromIntegration::class);

        $driver = $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-keep', 'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'employee_code' => 'EMP-9', 'metadata' => ['source' => 'samsara'],
        ]);

        $action->execute($team->id, $integration->id, [
            'external_id' => 'ext-keep', 'first_name' => 'Ana', 'last_name' => '', 'employee_code' => '', 'metadata' => [],
        ]);

        $fresh = $driver->fresh();
        $this->assertSame('EMP-9', $fresh->employee_code);
        $this->assertSame('Ruiz', $fresh->last_name);
        $this->assertSame(['source' => 'samsara'], $fresh->metadata_json);
    }

    public function test_it_refuses_to_claim_a_driver_owned_by_another_tenant(): void
    {
        Event::fake([DriverDiscovered::class]);

        [, $teamA, $provider, $integrationA] = $this->createSetup();
        $ownedDriver = Driver::factory()->create([
            'team_id' => $teamA->id,
            'full_name' => 'Tenant A Driver',
            'external_primary_id' => 'shared-ext',
        ]);
        DriverExternalReference::factory()->create([
            'driver_id' => $ownedDriver->id,
            'provider_id' => $provider->id,
            'external_id' => 'shared-ext',
        ]);

        $teamB = User::factory()->create()->currentTeam;
        $integrationB = TenantIntegration::factory()->create([
            'team_id' => $teamB->id,
            'provider_id' => $provider->id,
        ]);

        try {
            app(SyncDriverFromIntegration::class)->execute($teamB->id, $integrationB->id, [
                'external_id' => 'shared-ext',
                'name' => 'Hijacker',
            ]);
            $this->fail('Expected DriverExternalReferenceConflictException');
        } catch (DriverExternalReferenceConflictException $e) {
            $this->assertSame($teamB->id, $e->teamId);
            $this->assertSame('shared-ext', $e->externalId);
        }

        $this->assertSame('Tenant A Driver', $ownedDriver->fresh()->full_name);
        $this->assertSame(0, Driver::withoutGlobalScopes()->where('team_id', $teamB->id)->count());
        Event::assertNotDispatched(DriverDiscovered::class);
    }

    public function test_it_updates_its_own_driver_even_when_run_inside_another_tenant_context(): void
    {
        Event::fake([DriverDiscovered::class]);

        [, $team, , $integration] = $this->createSetup();
        $action = app(SyncDriverFromIntegration::class);

        $original = $action->execute($team->id, $integration->id, ['external_id' => 'own-ext', 'name' => 'Old Name']);

        $otherTeam = User::factory()->create()->currentTeam;

        $updated = TenantContext::for($otherTeam->id, fn () => $action->execute(
            $team->id,
            $integration->id,
            ['external_id' => 'own-ext', 'name' => 'New Name'],
        ));

        $this->assertSame($original->id, $updated->id);
        $this->assertSame('New Name', $updated->full_name);
        $this->assertSame(1, Driver::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }
}
