<?php

namespace Tests\Feature\Domains\Copilot;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RankAssetsToolTest extends TestCase
{
    use AssertsSystemLog, CopilotFixtures, RefreshDatabase, RunsCopilotTools;

    private const ALL = ['assets.view', 'incidents.view'];

    private CarbonImmutable $now;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);

        $this->now = CarbonImmutable::parse('2026-09-30 12:00');
        $this->travelTo($this->now);
        $this->team = Team::factory()->create();
    }

    private function unit(string $code, AssetCategory $category = AssetCategory::Vehicle, ?Team $team = null): Asset
    {
        return Asset::factory()->create([
            'team_id' => ($team ?? $this->team)->id,
            'asset_type_id' => AssetType::factory()->create(['category' => $category])->id,
            'code' => $code,
            'name' => "Kenworth {$code}",
            'last_seen_at' => $this->now,
        ]);
    }

    private function reading(Asset $asset, TelemetryType $type, mixed $value, CarbonImmutable $at): void
    {
        AssetTelemetrySnapshot::factory()->create([
            'asset_id' => $asset->id,
            'telemetry_type' => $type,
            'data_json' => ['value' => $value],
            'recorded_at' => $at,
        ]);
    }

    private function idle(Asset $asset, int $hours, int $daysAgo = 2): void
    {
        $start = $this->now->subDays($daysAgo);
        $this->reading($asset, TelemetryType::Ignition, 'Idle', $start);
        $this->reading($asset, TelemetryType::Ignition, 'On', $start->addHours($hours));
    }

    public function test_ranks_idle_hours_and_flags_the_outlier(): void
    {
        foreach (['T101', 'T102', 'T103', 'T104', 'T105'] as $code) {
            $this->idle($this->unit($code), 1);
        }
        $this->idle($this->unit('T555'), 9);

        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'idle_hours', 'order' => 'desc', 'limit' => 5]);

        $this->assertCount(5, $out['facts']['items']);
        $this->assertSame('T555', $out['facts']['items'][0]['code']);
        $this->assertTrue($out['facts']['items'][0]['outlier']);
        $this->assertFalse($out['facts']['items'][1]['outlier']);
        $this->assertSame(9.0, (float) $out['facts']['items'][0]['value']);
        $this->assertEqualsWithDelta(2.33, $out['facts']['average'], 0.01);
        $this->assertSame(6, $out['facts']['units_with_data']);

        $block = $this->toolBlock('ranking');
        $this->assertSame('h', $block['unit']);
        $this->assertSame('idle_hours', $block['metric']);
        $this->assertSame('Ralentí', $block['label']);
        $this->assertStringContainsString('/assets/', $block['items'][0]['href']);
        $this->assertSame('asset', $this->toolCollector->sources()[0]['kind']);
        $this->assertSystemLogged('copilot.tool.ran', fn ($c) => $c['input']['tool'] === 'rank_assets');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_ascending_order_lists_the_lowest_first(): void
    {
        $this->idle($this->unit('T101'), 2);
        $this->idle($this->unit('T102'), 5);

        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'idle_hours', 'order' => 'asc']);

        $this->assertSame(['T101', 'T102'], array_column($out['facts']['items'], 'code'));
    }

    public function test_ranks_fuel_used_excluding_refuels(): void
    {
        $a = $this->unit('TA');
        $b = $this->unit('TB');

        foreach ([[3, 80], [2, 40]] as [$days, $v]) {
            $this->reading($a, TelemetryType::Fuel, $v, $this->now->subDays($days));
        }
        foreach ([[4, 90], [3, 70], [2, 95], [1, 85]] as [$days, $v]) {
            $this->reading($b, TelemetryType::Fuel, $v, $this->now->subDays($days));
        }

        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'fuel_used_pct']);

        $this->assertSame(['TA', 'TB'], array_column($out['facts']['items'], 'code'));
        $this->assertSame(40.0, (float) $out['facts']['items'][0]['value']);
        $this->assertSame(30.0, (float) $out['facts']['items'][1]['value']);
        $this->assertSame('% tanque', $this->toolBlock('ranking')['unit']);
    }

    public function test_ranks_distance_from_odometer_delta(): void
    {
        $a = $this->unit('TA');
        $b = $this->unit('TB');
        $this->reading($a, TelemetryType::Odometer, 1000.0, $this->now->subDays(3));
        $this->reading($a, TelemetryType::Odometer, 1250.0, $this->now->subDay());
        $this->reading($b, TelemetryType::Odometer, 500.0, $this->now->subDays(3));
        $this->reading($b, TelemetryType::Odometer, 520.0, $this->now->subDay());
        // Outside the window: ignored.
        $this->reading($b, TelemetryType::Odometer, 100.0, $this->now->subDays(20));

        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'distance_km']);

        $this->assertSame('TA', $out['facts']['items'][0]['code']);
        $this->assertSame(250.0, (float) $out['facts']['items'][0]['value']);
        $this->assertSame(20.0, (float) $out['facts']['items'][1]['value']);
        $this->assertSame('km', $out['facts']['unit']);
    }

    public function test_ranks_incidents_by_opened_at_within_the_window(): void
    {
        $a = $this->unit('TA');
        $b = $this->unit('TB');
        Incident::factory()->count(3)->create(['team_id' => $this->team->id, 'asset_id' => $a->id, 'opened_at' => $this->now->subDays(2)]);
        Incident::factory()->create(['team_id' => $this->team->id, 'asset_id' => $b->id, 'opened_at' => $this->now->subDay()]);
        // Opened before the window: not counted.
        Incident::factory()->count(5)->create(['team_id' => $this->team->id, 'asset_id' => $b->id, 'opened_at' => $this->now->subDays(30)]);

        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'incidents']);

        $this->assertSame(['TA', 'TB'], array_column($out['facts']['items'], 'code'));
        $this->assertSame([3, 1], array_map('intval', array_column($out['facts']['items'], 'value')));
    }

    public function test_ranks_events_and_panics_by_type_code(): void
    {
        $a = $this->unit('TA');
        $b = $this->unit('TB');
        $panic = EventType::factory()->create(['code' => 'panic_button']);
        $brake = EventType::factory()->create(['code' => 'harsh_brake']);
        NormalizedEvent::factory()->count(2)->create(['team_id' => $this->team->id, 'asset_id' => $a->id, 'event_type_id' => $brake->id, 'occurred_at' => $this->now->subDay()]);
        NormalizedEvent::factory()->count(3)->create(['team_id' => $this->team->id, 'asset_id' => $b->id, 'event_type_id' => $panic->id, 'occurred_at' => $this->now->subDay()]);

        $panics = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'panics']);
        $this->assertSame(['TB'], array_column($panics['facts']['items'], 'code'));

        $brakes = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'events', 'event_type' => 'harsh_brake']);
        $this->assertSame(['TA'], array_column($brakes['facts']['items'], 'code'));

        $all = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'events']);
        $this->assertSame(['TB', 'TA'], array_column($all['facts']['items'], 'code'));
    }

    public function test_category_narrows_the_ranking(): void
    {
        $this->idle($this->unit('T101'), 2);
        $this->idle($this->unit('R200', AssetCategory::Trailer), 5);

        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'idle_hours', 'category' => AssetCategory::Vehicle->value]);

        $this->assertSame(['T101'], array_column($out['facts']['items'], 'code'));
    }

    public function test_ranks_incidents_requires_incident_permission(): void
    {
        $this->unit('TA');

        foreach (['incidents', 'events', 'panics'] as $metric) {
            $out = $this->callTool($this->team, ['assets.view'], 'rank_assets', ['metric' => $metric]);

            $this->assertSame([], $out['facts'], $metric);
            $this->assertSame('denied', $this->toolCollector->tools()[0]['status']);
        }

        $this->assertSystemLogged('copilot.tool.denied', fn ($c) => $c['reason'] === 'missing_permission' && $c['input']['tool'] === 'rank_assets');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_rank_without_telemetry_returns_notice(): void
    {
        $this->unit('TA');

        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'idle_hours']);

        $this->assertSame([], $out['facts']['items']);
        $this->assertNull($out['facts']['average']);
        $this->assertStringStartsWith('No hay datos de Ralentí', $this->toolBlock('notice')['text']);
        $this->assertNull($this->toolBlock('ranking'));
    }

    public function test_unknown_metric_is_rejected(): void
    {
        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'velocidad']);

        $this->assertSame('argumentos inválidos', $out['error']);
        $this->assertSystemLogged('copilot.tool.invalid_args', fn ($c) => $c['reason'] === 'validation_failed' && in_array('metric', $c['input']['fields'], true));
    }

    public function test_limit_over_ten_is_rejected(): void
    {
        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'idle_hours', 'limit' => 11]);

        $this->assertSame('argumentos inválidos', $out['error']);
    }

    public function test_ranking_over_many_units_runs_a_bounded_number_of_queries(): void
    {
        foreach (range(1, 30) as $i) {
            $asset = $this->unit("T{$i}");
            $this->reading($asset, TelemetryType::Odometer, 100.0, $this->now->subDays(2));
            $this->reading($asset, TelemetryType::Odometer, 100.0 + $i, $this->now->subDay());
        }

        DB::enableQueryLog();
        $out = $this->callTool($this->team, self::ALL, 'rank_assets', ['metric' => 'distance_km']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('T30', $out['facts']['items'][0]['code']);
        $this->assertLessThan(10, $queries);
    }
}
