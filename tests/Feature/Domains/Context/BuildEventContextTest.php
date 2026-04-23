<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventRecentHistorySnapshot;
use App\Domains\Context\Models\Geofence;
use App\Domains\Context\Models\GeofenceMatch;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BuildEventContextTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_builds_full_snapshot_when_asset_driver_and_location_present(): void
    {
        Event::fake([EventContextBuilt::class]);

        $severity = EventSeverity::factory()->create(['code' => 'high']);
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);

        Geofence::factory()->create([
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
            'category' => GeofenceCategory::RiskZone,
        ]);

        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'driver_id' => $driver->id,
            'event_severity_id' => $severity->id,
            'payload_normalized_json' => [
                'location' => ['latitude' => 19.40, 'longitude' => -99.13],
            ],
        ]);

        $snapshot = app(BuildEventContext::class)->execute($normalizedEvent);

        $this->assertInstanceOf(EventContextSnapshot::class, $snapshot);
        $this->assertSame($this->teamId, $snapshot->team_id);
        $this->assertSame($asset->id, $snapshot->asset_id);
        $this->assertSame($driver->id, $snapshot->driver_id);
        $this->assertSame(1, $snapshot->context_version);
        $this->assertTrue($snapshot->signals_json['is_in_sensitive_geofence']);
        $this->assertNotNull($snapshot->driver_snapshot_json);
        $this->assertSame(1, GeofenceMatch::query()->where('normalized_event_id', $normalizedEvent->id)->count());
        $this->assertNotNull(EventRecentHistorySnapshot::query()->where('normalized_event_id', $normalizedEvent->id)->first());
        $this->assertNotNull(OperationalContextProfile::withoutGlobalScopes()->where('normalized_event_id', $normalizedEvent->id)->first());

        Event::assertDispatched(EventContextBuilt::class);
    }

    public function test_builds_partial_snapshot_without_driver(): void
    {
        Event::fake([EventContextBuilt::class]);

        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'driver_id' => null,
            'payload_normalized_json' => ['location' => ['latitude' => 0.0, 'longitude' => 0.0]],
        ]);

        $snapshot = app(BuildEventContext::class)->execute($normalizedEvent);

        $this->assertNull($snapshot->driver_snapshot_json);
        $this->assertNotNull($snapshot->asset_snapshot_json);
    }

    public function test_idempotency_increments_context_version_without_duplicating(): void
    {
        Event::fake([EventContextBuilt::class]);

        $normalizedEvent = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $first = app(BuildEventContext::class)->execute($normalizedEvent);
        $second = app(BuildEventContext::class)->execute($normalizedEvent->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $first->context_version);
        $this->assertSame(2, $second->context_version);

        $count = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEvent->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_dispatches_event_context_built_with_profile(): void
    {
        Event::fake([EventContextBuilt::class]);

        $normalizedEvent = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        app(BuildEventContext::class)->execute($normalizedEvent);

        Event::assertDispatched(EventContextBuilt::class, function (EventContextBuilt $event) use ($normalizedEvent) {
            return $event->snapshot->normalized_event_id === $normalizedEvent->id
                && $event->profile->normalized_event_id === $normalizedEvent->id;
        });
    }

    public function test_location_falls_back_to_asset_latest_location_when_payload_missing(): void
    {
        Event::fake([EventContextBuilt::class]);

        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $asset->locationSnapshots()->create([
            'latitude' => 19.40,
            'longitude' => -99.13,
            'recorded_at' => now(),
            'source' => 'gps',
        ]);

        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'payload_normalized_json' => [],
        ]);

        $snapshot = app(BuildEventContext::class)->execute($normalizedEvent);

        $this->assertSame('asset_latest_location', $snapshot->location_snapshot_json['source']);
        $this->assertEqualsWithDelta(19.40, $snapshot->location_snapshot_json['latitude'], 0.001);
    }

    public function test_geofence_match_uses_inside_match_type_for_zone(): void
    {
        Event::fake([EventContextBuilt::class]);

        $geofence = Geofence::factory()->create([
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

        $normalizedEvent = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'payload_normalized_json' => ['location' => ['latitude' => 19.40, 'longitude' => -99.13]],
        ]);

        app(BuildEventContext::class)->execute($normalizedEvent);

        $match = GeofenceMatch::query()
            ->where('normalized_event_id', $normalizedEvent->id)
            ->where('geofence_id', $geofence->id)
            ->first();

        $this->assertNotNull($match);
        $this->assertSame(GeofenceMatchType::Inside, $match->match_type);
    }
}
