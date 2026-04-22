<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_soft_deletes_driver(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Soft',
            'last_name' => 'Delete',
            'full_name' => 'Soft Delete',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $driver->delete();

        $this->assertSoftDeleted('drivers', [
            'id' => $driver->id,
        ]);

        $this->assertNotNull(
            Driver::withoutGlobalScopes()->withTrashed()->find($driver->id),
            'Soft-deleted driver should still be retrievable via withTrashed()',
        );
    }

    public function test_it_preserves_assignment_history_after_soft_delete(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $assetType = AssetType::factory()->vehicle()->create();

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'History',
            'last_name' => 'Driver',
            'full_name' => 'History Driver',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Test Asset',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverAssignment::create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => now()->subDay(),
            'ended_at' => now(),
            'source' => AssignmentSource::Integration,
        ]);

        $driver->delete();

        $this->assertDatabaseHas('driver_assignments', [
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
        ]);

        $assignmentCount = DriverAssignment::where('driver_id', $driver->id)->count();

        $this->assertEquals(
            1,
            $assignmentCount,
            'Assignment history should be preserved after the driver is soft-deleted',
        );
    }
}
