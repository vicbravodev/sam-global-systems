<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Actions\BuildAnalyticsSnapshot;
use App\Domains\Analytics\Actions\CalculateKPI;
use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Assets\Models\Asset;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * `active_assets` ignored the period (a rebuilt history was a flat line at
 * today's fleet size) and counted inactive assets; `active_integrations`
 * counted every integration regardless of status.
 */
class ActiveFleetCountsTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 9, 20)->setTime(12, 0));

        $this->team = Team::factory()->create();

        // Joined the fleet on Sep 1, Sep 10 and Sep 15.
        foreach ([1, 10, 15] as $day) {
            Asset::factory()->active()->create([
                'team_id' => $this->team->id,
                'first_seen_at' => now()->setDate(2026, 9, $day)->setTime(8, 0),
            ]);
        }

        // Inactive: never counted.
        Asset::factory()->inactive()->create([
            'team_id' => $this->team->id,
            'first_seen_at' => now()->setDate(2026, 9, 1),
        ]);

        // Removed on Sep 12: part of the fleet before, not after.
        Asset::factory()->active()->create([
            'team_id' => $this->team->id,
            'first_seen_at' => now()->setDate(2026, 9, 1),
        ])->delete();
        Asset::withTrashed()->where('team_id', $this->team->id)->whereNotNull('deleted_at')
            ->update(['deleted_at' => now()->setDate(2026, 9, 12)->setTime(10, 0)]);
    }

    public function test_active_assets_kpi_depends_on_the_day(): void
    {
        $metric = MetricDefinition::factory()->create(['code' => 'active_assets', 'unit' => 'count']);

        $expected = ['2026-09-05' => 2, '2026-09-11' => 3, '2026-09-13' => 2, '2026-09-16' => 3];

        foreach ($expected as $day => $count) {
            $start = now()->parse($day)->startOfDay();

            $record = app(CalculateKPI::class)->execute($metric, $this->team->id, PeriodType::Daily, $start, $start->endOfDay());

            $this->assertEquals($count, $record->value, "active_assets on {$day}");
        }
    }

    public function test_tenant_overview_snapshot_counts_only_active_assets_and_integrations(): void
    {
        TenantIntegration::factory()->active()->create(['team_id' => $this->team->id]);
        TenantIntegration::factory()->inactive()->create(['team_id' => $this->team->id]);
        TenantIntegration::factory()->error()->create(['team_id' => $this->team->id]);

        $day = now()->parse('2026-09-19')->startOfDay();

        $snapshot = app(BuildAnalyticsSnapshot::class)
            ->execute($this->team->id, SnapshotType::TenantOverview, $day, $day->endOfDay());

        $this->assertSame(3, $snapshot->snapshot_json['active_assets']);
        $this->assertSame(1, $snapshot->snapshot_json['active_integrations']);
    }

    public function test_other_tenants_fleet_is_never_counted(): void
    {
        $other = Team::factory()->create();
        Asset::factory()->active()->count(5)->create(['team_id' => $other->id, 'first_seen_at' => now()->subMonth()]);
        TenantIntegration::factory()->active()->count(2)->create(['team_id' => $other->id]);

        $metric = MetricDefinition::factory()->create(['code' => 'active_assets', 'unit' => 'count']);
        $day = now()->parse('2026-09-19')->startOfDay();

        $record = $this->assertNoTenantLeak($this->team, fn () => app(CalculateKPI::class)
            ->execute($metric, $this->team->id, PeriodType::Daily, $day, $day->endOfDay()));

        $snapshot = $this->assertNoTenantLeak($this->team, fn () => app(BuildAnalyticsSnapshot::class)
            ->execute($this->team->id, SnapshotType::TenantOverview, $day, $day->endOfDay()));

        $this->assertEquals(3, $record->value);
        $this->assertSame(3, $snapshot->snapshot_json['active_assets']);
        $this->assertSame(0, $snapshot->snapshot_json['active_integrations']);
    }
}
