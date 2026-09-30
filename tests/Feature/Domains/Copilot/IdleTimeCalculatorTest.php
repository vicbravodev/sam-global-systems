<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Copilot\Data\CopilotPeriod;
use App\Domains\Copilot\Data\CopilotToolContext;
use App\Domains\Copilot\Support\IdleTimeCalculator;
use App\Domains\Copilot\Tools\AssetEngineTool;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdleTimeCalculatorTest extends TestCase
{
    use CopilotFixtures;
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);

        $this->now = CarbonImmutable::parse('2026-09-30 12:00');
        $this->travelTo($this->now);
    }

    private function engine(Asset $asset, string $state, CarbonImmutable $at): void
    {
        AssetTelemetrySnapshot::factory()->create([
            'asset_id' => $asset->id,
            'telemetry_type' => TelemetryType::Ignition,
            'data_json' => ['value' => $state],
            'recorded_at' => $at,
        ]);
    }

    private function speed(Asset $asset, float $speed, CarbonImmutable $at): void
    {
        AssetLocationSnapshot::factory()->create([
            'asset_id' => $asset->id,
            'speed' => $speed,
            'recorded_at' => $at,
        ]);
    }

    private function bareAsset(int $teamId, ?CarbonImmutable $lastSeen = null): Asset
    {
        return Asset::factory()->create(['team_id' => $teamId, 'last_seen_at' => $lastSeen ?? $this->now]);
    }

    public function test_sums_idle_segments_clipped_to_the_window(): void
    {
        $now = $this->now;
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team);
        $this->engine($asset, 'Idle', $now->subHours(10));
        $this->engine($asset, 'On', $now->subHours(7));
        $this->engine($asset, 'Idle', $now->subHours(3));
        $this->engine($asset, 'Off', $now->subHours(1));

        $summary = app(IdleTimeCalculator::class)->forAsset($asset, $now->subHours(8), $now);

        $this->assertSame('engine_state', $summary->source);
        $this->assertSame(3.0, $summary->hours);
        $this->assertCount(2, $summary->segments);
    }

    public function test_open_idle_segment_is_cut_at_last_seen_when_asset_is_silent(): void
    {
        $now = $this->now;
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->bareAsset($team->id, $now->subHours(5));
        $this->engine($asset, 'Idle', $now->subHours(6));

        $summary = app(IdleTimeCalculator::class)->forAsset($asset, $now->subHours(8), $now);

        $this->assertSame('engine_state', $summary->source);
        $this->assertSame(1.0, $summary->hours);
    }

    public function test_falls_back_to_ignition_on_with_speed_below_three_kph(): void
    {
        $now = $this->now;
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->bareAsset($team->id);
        $this->engine($asset, 'On', $now->subHours(2));
        $this->engine($asset, 'Off', $now->subHours(1));

        for ($m = 120; $m >= 100; $m -= 2) {
            $this->speed($asset, 0, $now->subMinutes($m));
        }
        $this->speed($asset, 50, $now->subMinutes(98));
        $this->speed($asset, 50, $now->subMinutes(90));

        $summary = app(IdleTimeCalculator::class)->forAsset($asset, $now->subHours(3), $now);

        $this->assertSame('ignition_speed', $summary->source);
        $this->assertSame(0.33, $summary->hours);
        $this->assertCount(1, $summary->segments);
    }

    public function test_short_stops_under_three_minutes_do_not_count(): void
    {
        $now = $this->now;
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->bareAsset($team->id);
        $this->engine($asset, 'On', $now->subHours(1));
        $this->speed($asset, 0, $now->subMinutes(30));
        $this->speed($asset, 0, $now->subMinutes(29));

        $summary = app(IdleTimeCalculator::class)->forAsset($asset, $now->subHours(2), $now);

        $this->assertSame(0.0, $summary->hours);
        $this->assertSame('none', $summary->source);
        $this->assertSame([], $summary->segments);
    }

    public function test_batch_never_reads_other_tenants(): void
    {
        $now = $this->now;
        [, $teamA] = $this->memberWithRole('supervisor');
        [, $teamB] = $this->memberWithRole('supervisor');
        $assetB = $this->bareAsset($teamB->id);
        $this->engine($assetB, 'Idle', $now->subHours(3));

        $result = app(IdleTimeCalculator::class)->forAssets($teamA->id, [$assetB->id], $now->subHours(8), $now);

        $this->assertSame([], $result);
    }

    public function test_carry_in_state_uses_recorded_at_not_insertion_order(): void
    {
        $now = $this->now;
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->bareAsset($team->id);
        // Newest before the window is Idle (inserted first); an older Off is
        // backfilled later and gets a higher id.
        $this->engine($asset, 'Idle', $now->subHours(9));
        $this->engine($asset, 'Off', $now->subHours(12));
        $this->engine($asset, 'Off', $now->subHours(6));

        $summary = app(IdleTimeCalculator::class)->forAsset($asset, $now->subHours(8), $now->subHours(7));

        $this->assertSame('engine_state', $summary->source);
        $this->assertSame(1.0, $summary->hours);
    }

    public function test_running_ignition_state_counts_as_on_in_the_fallback(): void
    {
        $now = $this->now;
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->bareAsset($team->id);
        $this->engine($asset, 'running', $now->subHours(2));
        $this->engine($asset, 'Off', $now->subHours(1));
        $this->speed($asset, 0, $now->subMinutes(110));
        $this->speed($asset, 0, $now->subMinutes(100));

        $summary = app(IdleTimeCalculator::class)->forAsset($asset, $now->subHours(3), $now);

        $this->assertSame('ignition_speed', $summary->source);
        $this->assertSame(0.17, $summary->hours);
    }

    public function test_engine_tool_reports_idle_hours(): void
    {
        $now = $this->now;
        [, $team] = $this->memberWithRole('supervisor');
        $asset = $this->truckWithTelemetry($team);
        $this->engine($asset, 'Idle', $now->subHours(3));
        $this->engine($asset, 'Off', $now->subHours(1));

        $context = new CopilotToolContext(
            teamId: $team->id,
            teamSlug: $team->slug,
            permissions: ['assets.view'],
            period: new CopilotPeriod($now->subHours(8), $now, 'últimas 8 horas', 1),
            asset: $asset,
        );

        $result = app(AssetEngineTool::class)->run($context);

        $this->assertSame(2.0, $result->facts['idle_hours']);
        $this->assertSame('engine_state', $result->facts['idle_source']);
    }
}
