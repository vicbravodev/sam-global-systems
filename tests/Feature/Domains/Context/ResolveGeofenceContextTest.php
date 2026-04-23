<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Actions\ResolveGeofenceContext;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Enums\GeofenceType;
use App\Domains\Context\Models\Geofence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveGeofenceContextTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
        Cache::flush();
    }

    public function test_matches_zone_when_point_is_inside_polygon(): void
    {
        Geofence::factory()->zone()->create([
            'team_id' => $this->teamId,
            'geometry_json' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-99.20, 19.30],
                    [-99.05, 19.30],
                    [-99.05, 19.50],
                    [-99.20, 19.50],
                    [-99.20, 19.30],
                ]],
            ],
        ]);

        $matches = app(ResolveGeofenceContext::class)->execute(19.40, -99.13, $this->teamId);

        $this->assertCount(1, $matches);
        $this->assertSame(GeofenceMatchType::Inside, $matches[0]['match_type']);
    }

    public function test_matches_point_when_within_radius(): void
    {
        Geofence::factory()->point()->create([
            'team_id' => $this->teamId,
            'geometry_json' => [
                'type' => 'Point',
                'coordinates' => [-99.1332, 19.4326],
                'radius_meters' => 500,
            ],
        ]);

        $matches = app(ResolveGeofenceContext::class)->execute(19.4326, -99.1332, $this->teamId);

        $this->assertCount(1, $matches);
        $this->assertSame(GeofenceMatchType::Inside, $matches[0]['match_type']);
        $this->assertSame(0, $matches[0]['distance_meters']);
    }

    public function test_matches_point_near_boundary_within_100m_buffer(): void
    {
        Geofence::factory()->point()->create([
            'team_id' => $this->teamId,
            'geometry_json' => [
                'type' => 'Point',
                'coordinates' => [-99.0, 19.0],
                'radius_meters' => 100,
            ],
        ]);

        $matches = app(ResolveGeofenceContext::class)->execute(19.0015, -99.0, $this->teamId);

        $this->assertCount(1, $matches);
        $this->assertSame(GeofenceMatchType::NearBoundary, $matches[0]['match_type']);
    }

    public function test_returns_empty_when_point_outside(): void
    {
        Geofence::factory()->zone()->create([
            'team_id' => $this->teamId,
            'geometry_json' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-99.20, 19.30],
                    [-99.05, 19.30],
                    [-99.05, 19.50],
                    [-99.20, 19.50],
                    [-99.20, 19.30],
                ]],
            ],
        ]);

        $matches = app(ResolveGeofenceContext::class)->execute(10.0, -50.0, $this->teamId);

        $this->assertSame([], $matches);
    }

    public function test_ignores_inactive_geofences(): void
    {
        Geofence::factory()->zone()->inactive()->create([
            'team_id' => $this->teamId,
            'geometry_json' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-99.20, 19.30],
                    [-99.05, 19.30],
                    [-99.05, 19.50],
                    [-99.20, 19.50],
                    [-99.20, 19.30],
                ]],
            ],
        ]);

        $matches = app(ResolveGeofenceContext::class)->execute(19.40, -99.13, $this->teamId);

        $this->assertSame([], $matches);
    }

    public function test_returns_empty_when_coordinates_null(): void
    {
        $matches = app(ResolveGeofenceContext::class)->execute(null, null, $this->teamId);

        $this->assertSame([], $matches);
    }

    public function test_cache_invalidation_clears_stored_list(): void
    {
        Cache::put("team:{$this->teamId}:geofences", collect(), 300);
        $this->assertTrue(Cache::has("team:{$this->teamId}:geofences"));

        ResolveGeofenceContext::invalidateCacheForTeam($this->teamId);

        $this->assertFalse(Cache::has("team:{$this->teamId}:geofences"));
    }

    public function test_ignores_geofence_with_invalid_geometry(): void
    {
        Geofence::factory()->create([
            'team_id' => $this->teamId,
            'geofence_type' => GeofenceType::Zone,
            'geometry_json' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 1]]]],
        ]);

        $matches = app(ResolveGeofenceContext::class)->execute(19.40, -99.13, $this->teamId);

        $this->assertSame([], $matches);
    }
}
