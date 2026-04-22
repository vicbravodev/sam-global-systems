<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Drivers\Actions\AssignDriverToAsset;
use App\Domains\Drivers\Actions\ResolveDriverForEvent;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Events\DriverAssigned;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DriverAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function createSetup(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $assetType = AssetType::factory()->vehicle()->create();
        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Test Truck',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Test',
            'last_name' => 'Driver',
            'full_name' => 'Test Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return [$user, $team, $asset, $driver];
    }

    public function test_it_assigns_driver_to_asset(): void
    {
        Event::fake([DriverAssigned::class]);

        [$user, $team, $asset, $driver] = $this->createSetup();

        $action = app(AssignDriverToAsset::class);

        $assignment = $action->execute(
            $team->id,
            $driver->id,
            $asset->id,
            AssignmentType::PrimaryDriver,
            AssignmentSource::Integration,
        );

        $this->assertNotNull(
            $assignment,
            'AssignDriverToAsset should return a DriverAssignment instance',
        );

        $this->assertDatabaseHas('driver_assignments', [
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'assignment_type' => 'primary_driver',
        ]);

        Event::assertDispatched(DriverAssigned::class, function ($event) use ($team, $driver, $asset) {
            return $event->teamId === $team->id
                && $event->driverId === $driver->id
                && $event->assetId === $asset->id;
        });
    }

    public function test_it_ends_existing_primary_assignment_when_new_one_created(): void
    {
        Event::fake([DriverAssigned::class]);

        [$user, $team, $asset, $driver] = $this->createSetup();

        $secondDriver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Second',
            'last_name' => 'Driver',
            'full_name' => 'Second Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $action = app(AssignDriverToAsset::class);

        $firstAssignment = $action->execute(
            $team->id,
            $driver->id,
            $asset->id,
            AssignmentType::PrimaryDriver,
            AssignmentSource::Integration,
        );

        $action->execute(
            $team->id,
            $secondDriver->id,
            $asset->id,
            AssignmentType::PrimaryDriver,
            AssignmentSource::Integration,
        );

        $firstAssignment->refresh();

        $this->assertNotNull(
            $firstAssignment->ended_at,
            'Previous primary assignment should have ended_at set when a new primary assignment is created for the same asset',
        );
    }

    public function test_it_allows_multiple_assignment_types_simultaneously(): void
    {
        Event::fake([DriverAssigned::class]);

        [$user, $team, $asset, $driver] = $this->createSetup();

        $action = app(AssignDriverToAsset::class);

        $action->execute(
            $team->id,
            $driver->id,
            $asset->id,
            AssignmentType::PrimaryDriver,
            AssignmentSource::Integration,
        );

        $secondDriver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Helper',
            'last_name' => 'Person',
            'full_name' => 'Helper Person',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $action->execute(
            $team->id,
            $secondDriver->id,
            $asset->id,
            AssignmentType::ResponsibleParty,
            AssignmentSource::Manual,
        );

        $activeCount = DriverAssignment::where('asset_id', $asset->id)
            ->whereNull('ended_at')
            ->count();

        $this->assertEquals(
            2,
            $activeCount,
            'Multiple assignment types (PrimaryDriver + ResponsibleParty) should coexist for the same asset',
        );
    }

    public function test_it_resolves_driver_for_event_at_specific_timestamp(): void
    {
        [$user, $team, $asset, $driver] = $this->createSetup();

        $assignedAt = now()->subHours(2);

        DriverAssignment::create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => $assignedAt,
            'source' => AssignmentSource::Integration,
        ]);

        $action = app(ResolveDriverForEvent::class);

        $resolved = $action->execute($asset->id, now()->subHour());

        $this->assertNotNull(
            $resolved,
            'ResolveDriverForEvent should return a driver for a timestamp within an active assignment period',
        );

        $this->assertEquals(
            $driver->id,
            $resolved->id,
            'Resolved driver should match the driver assigned at the queried timestamp',
        );
    }

    public function test_it_returns_null_when_no_driver_assigned_at_timestamp(): void
    {
        [$user, $team, $asset, $driver] = $this->createSetup();

        DriverAssignment::create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => now()->subHour(),
            'source' => AssignmentSource::Integration,
        ]);

        $action = app(ResolveDriverForEvent::class);

        $resolved = $action->execute($asset->id, now()->subHours(3));

        $this->assertNull(
            $resolved,
            'ResolveDriverForEvent should return null when no assignment was active at the queried timestamp',
        );
    }

    public function test_it_resolves_correct_driver_across_multiple_assignments(): void
    {
        [$user, $team, $asset, $driver] = $this->createSetup();

        $secondDriver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Second',
            'last_name' => 'Driver',
            'full_name' => 'Second Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverAssignment::create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => now()->subHours(6),
            'ended_at' => now()->subHours(3),
            'source' => AssignmentSource::Integration,
        ]);

        DriverAssignment::create([
            'team_id' => $team->id,
            'driver_id' => $secondDriver->id,
            'asset_id' => $asset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => now()->subHours(3),
            'source' => AssignmentSource::Integration,
        ]);

        $action = app(ResolveDriverForEvent::class);

        $resolvedEarly = $action->execute($asset->id, now()->subHours(4));
        $resolvedLate = $action->execute($asset->id, now()->subHour());

        $this->assertEquals(
            $driver->id,
            $resolvedEarly->id,
            'Should resolve the first driver for a timestamp within the first assignment period',
        );

        $this->assertEquals(
            $secondDriver->id,
            $resolvedLate->id,
            'Should resolve the second driver for a timestamp within the second assignment period',
        );
    }
}
