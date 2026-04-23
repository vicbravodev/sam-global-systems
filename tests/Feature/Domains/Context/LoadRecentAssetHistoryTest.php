<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Actions\LoadRecentAssetHistory;
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
}
