<?php

namespace Tests\Feature\Domains\Drivers;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use App\Domains\Drivers\Models\DriverContact;
use App\Domains\Drivers\Models\DriverDocument;
use App\Domains\Drivers\Models\DriverRiskProfile;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function createSetup(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        return [$user, $team];
    }

    public function test_it_lists_drivers_with_filters(): void
    {
        [$user, $team] = $this->createSetup();

        Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Active',
            'last_name' => 'Driver',
            'full_name' => 'Active Driver',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Suspended',
            'last_name' => 'Worker',
            'full_name' => 'Suspended Worker',
            'status' => DriverStatus::Suspended,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Active',
            'last_name' => 'Operator',
            'full_name' => 'Active Operator',
            'employee_code' => 'OP-001',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/drivers");
        $response->assertOk();
        $this->assertCount(
            3,
            $response->json('data'),
            'Unfiltered driver list should return all 3 drivers for the team',
        );

        $response = $this->getJson("/api/{$team->slug}/drivers?status=active");
        $response->assertOk();
        $this->assertCount(
            2,
            $response->json('data'),
            'Filtering by status=active should return only the 2 active drivers',
        );

        $response = $this->getJson("/api/{$team->slug}/drivers?search=Operator");
        $response->assertOk();
        $this->assertCount(
            1,
            $response->json('data'),
            'Searching for "Operator" should return only the 1 matching driver',
        );

        $response = $this->getJson("/api/{$team->slug}/drivers?search=OP-001");
        $response->assertOk();
        $this->assertCount(
            1,
            $response->json('data'),
            'Searching by employee_code "OP-001" should return the matching driver',
        );
    }

    public function test_it_shows_driver_detail_with_related_data(): void
    {
        [$user, $team] = $this->createSetup();

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Detail',
            'last_name' => 'Driver',
            'full_name' => 'Detail Driver',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverContact::factory()->create(['driver_id' => $driver->id]);
        DriverDocument::factory()->create(['driver_id' => $driver->id]);
        DriverRiskProfile::factory()->create(['driver_id' => $driver->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/drivers/{$driver->id}");

        $response->assertOk();

        $data = $response->json('data');

        $this->assertEquals(
            $driver->id,
            $data['id'],
            'Show endpoint should return the requested driver',
        );

        $this->assertArrayHasKey(
            'contacts',
            $data,
            'Driver detail should include eager-loaded contacts',
        );

        $this->assertArrayHasKey(
            'documents',
            $data,
            'Driver detail should include eager-loaded documents',
        );

        $this->assertArrayHasKey(
            'risk_profile',
            $data,
            'Driver detail should include eager-loaded risk profile',
        );
    }

    public function test_it_returns_paginated_assignment_history(): void
    {
        [$user, $team] = $this->createSetup();
        $assetType = AssetType::factory()->vehicle()->create();

        $driver = Driver::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'first_name' => 'Assigned',
            'last_name' => 'Driver',
            'full_name' => 'Assigned Driver',
            'status' => DriverStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Test Truck',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        DriverAssignment::create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => now()->subDays(5),
            'ended_at' => now()->subDays(2),
            'source' => AssignmentSource::Integration,
        ]);

        DriverAssignment::create([
            'team_id' => $team->id,
            'driver_id' => $driver->id,
            'asset_id' => $asset->id,
            'assignment_type' => AssignmentType::PrimaryDriver,
            'started_at' => now()->subDays(2),
            'source' => AssignmentSource::Integration,
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/drivers/{$driver->id}/assignments");

        $response->assertOk();

        $this->assertCount(
            2,
            $response->json('data'),
            'Assignment history should return all 2 assignments for the driver',
        );
    }

    public function test_it_rejects_manual_driver_creation(): void
    {
        [$user, $team] = $this->createSetup();

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/drivers", [
            'first_name' => 'Manual',
            'last_name' => 'Driver',
        ]);

        $this->assertTrue(
            in_array($response->status(), [404, 405]),
            'POST to /drivers should be rejected (404 or 405) since drivers cannot be created manually, got '.$response->status(),
        );
    }
}
