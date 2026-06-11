<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Actions\LoadRecentAssetHistory;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadRecentAssetHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_returns_empty_window_when_asset_null(): void
    {
        $result = app(LoadRecentAssetHistory::class)->execute(null, null, now());

        $this->assertSame(0, $result['recent_events_count']);
        $this->assertSame(0, $result['recent_same_type_count']);
        $this->assertSame([], $result['recent_locations_json']);
    }

    public function test_counts_events_in_window(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now()->subMinutes(30),
        ]);

        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now()->subMinutes(90),
        ]);

        $result = app(LoadRecentAssetHistory::class)->execute($asset->id, null, now(), 60);

        $this->assertSame(1, $result['recent_events_count']);
    }

    public function test_counts_same_type_and_high_severity(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $type = EventType::factory()->create();
        $highSeverity = EventSeverity::factory()->create(['code' => 'high']);

        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'event_severity_id' => $highSeverity->id,
            'occurred_at' => now()->subMinutes(10),
        ]);

        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $type->id,
            'occurred_at' => now()->subMinutes(20),
        ]);

        $result = app(LoadRecentAssetHistory::class)->execute($asset->id, $type->id, now(), 60);

        $this->assertSame(2, $result['recent_events_count']);
        $this->assertSame(2, $result['recent_same_type_count']);
        $this->assertSame(1, $result['recent_high_severity_count']);
    }

    public function test_collects_recent_locations_from_payload(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now()->subMinutes(10),
            'payload_normalized_json' => ['location' => ['latitude' => 19.4, 'longitude' => -99.1]],
        ]);

        $result = app(LoadRecentAssetHistory::class)->execute($asset->id, null, now(), 60);

        $this->assertCount(1, $result['recent_locations_json']);
        $this->assertSame(19.4, $result['recent_locations_json'][0]['latitude']);
    }

    private function makeSafetyType(string $code, ?EventCategory $category = null): EventType
    {
        $category ??= EventCategory::query()->where('code', 'safety')->first()
            ?? EventCategory::factory()->safety()->create();

        return EventType::factory()->create(['code' => $code, 'category_id' => $category->id]);
    }

    public function test_safety_correlation_counts_events_before_and_after_excluding_the_current_one(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $harshBraking = $this->makeSafetyType('harsh_braking');
        $speeding = $this->makeSafetyType('speeding');

        // 5 min before and 10 min after the event under evaluation.
        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $harshBraking->id,
            'occurred_at' => now()->subMinutes(5),
        ]);
        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $speeding->id,
            'occurred_at' => now()->addMinutes(10),
        ]);

        // The event under evaluation itself must not count.
        $current = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $harshBraking->id,
            'occurred_at' => now(),
        ]);

        $result = app(LoadRecentAssetHistory::class)->execute(
            $asset->id,
            null,
            now(),
            60,
            correlationMinutes: 30,
            excludeEventId: $current->id,
        );

        $this->assertSame(2, $result['nearby_safety_events_count']);
        $this->assertSame(['harsh_braking' => 1, 'speeding' => 1], $result['nearby_safety_breakdown']);
        $this->assertTrue($result['harsh_driving_near_event']);
    }

    public function test_safety_correlation_respects_the_window_and_ignores_non_safety_categories(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $harshBraking = $this->makeSafetyType('harsh_braking');

        $operational = EventCategory::factory()->create(['code' => 'operational']);
        $geofenceExit = EventType::factory()->create(['code' => 'geofence_exit', 'category_id' => $operational->id]);

        // Outside the ±15 min correlation window.
        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $harshBraking->id,
            'occurred_at' => now()->subMinutes(20),
        ]);

        // Inside the window but not a safety/emergency category.
        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $geofenceExit->id,
            'occurred_at' => now()->subMinutes(5),
        ]);

        $result = app(LoadRecentAssetHistory::class)->execute($asset->id, null, now(), 60, correlationMinutes: 15);

        $this->assertSame(0, $result['nearby_safety_events_count']);
        $this->assertFalse($result['harsh_driving_near_event']);
    }

    public function test_safety_correlation_without_harsh_maneuvers_does_not_flag_harsh_driving(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $speeding = $this->makeSafetyType('speeding');

        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'event_type_id' => $speeding->id,
            'occurred_at' => now()->subMinutes(3),
        ]);

        $result = app(LoadRecentAssetHistory::class)->execute($asset->id, null, now(), 60);

        $this->assertSame(1, $result['nearby_safety_events_count']);
        $this->assertFalse($result['harsh_driving_near_event']);
    }

    public function test_safety_correlation_ignores_other_assets(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $otherAsset = Asset::factory()->create(['team_id' => $this->teamId]);
        $harshBraking = $this->makeSafetyType('harsh_braking');

        NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $otherAsset->id,
            'event_type_id' => $harshBraking->id,
            'occurred_at' => now()->subMinutes(3),
        ]);

        $result = app(LoadRecentAssetHistory::class)->execute($asset->id, null, now(), 60);

        $this->assertSame(0, $result['nearby_safety_events_count']);
        $this->assertFalse($result['harsh_driving_near_event']);
    }
}
