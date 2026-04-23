<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceType;
use App\Domains\Context\Models\Geofence;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_index_returns_paginated_geofences(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Geofence::factory()->count(3)->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/geofences");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_filters_by_active_and_category(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        Geofence::factory()->create(['team_id' => $team->id, 'is_active' => true, 'category' => GeofenceCategory::RiskZone]);
        Geofence::factory()->create(['team_id' => $team->id, 'is_active' => false, 'category' => GeofenceCategory::RiskZone]);
        Geofence::factory()->create(['team_id' => $team->id, 'is_active' => true, 'category' => GeofenceCategory::ClientSite]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/geofences?active=1&category=risk_zone");
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_store_creates_geofence(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $payload = [
            'name' => 'HQ',
            'code' => 'GF-HQ',
            'geofence_type' => GeofenceType::Zone->value,
            'category' => GeofenceCategory::ClientSite->value,
            'geometry_json' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]],
        ];

        $response = $this->postJson("/api/{$team->slug}/geofences", $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('geofences', ['code' => 'GF-HQ', 'team_id' => $team->id]);
    }

    public function test_update_changes_geofence(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $geofence = Geofence::factory()->create(['team_id' => $team->id, 'name' => 'Old']);

        $this->actingAs($user);

        $response = $this->putJson("/api/{$team->slug}/geofences/{$geofence->id}", [
            'name' => 'New',
            'category' => GeofenceCategory::RiskZone->value,
        ]);

        $response->assertOk();
        $this->assertSame('New', $geofence->fresh()->name);
    }

    public function test_destroy_deletes_geofence(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $geofence = Geofence::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->deleteJson("/api/{$team->slug}/geofences/{$geofence->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('geofences', ['id' => $geofence->id]);
    }

    public function test_tenant_isolation_blocks_foreign_geofences(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $geofence = Geofence::factory()->create(['team_id' => $userA->currentTeam->id]);

        $this->actingAs($userB);

        $response = $this->putJson("/api/{$userA->currentTeam->slug}/geofences/{$geofence->id}", ['name' => 'Hijack']);
        $this->assertContains($response->status(), [403, 404]);
    }
}
