<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\User;
use Database\Seeders\AssetMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La muestra nocturna sólo cuenta unidades vigiladas y alimenta el medidor
 * de tracto-días, base del cobro (decisión 2026-09-28).
 */
class AssetDayMeteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_monitored_units_count_and_asset_days_are_recorded(): void
    {
        $this->seed(AssetMeterSeeder::class);

        $team = User::factory()->create()->currentTeam;
        $type = AssetType::factory()->vehicle()->create();

        Asset::factory()->count(3)->create(['team_id' => $team->id, 'asset_type_id' => $type->id]);
        Asset::factory()->pendingMonitoring()->count(2)->create(['team_id' => $team->id, 'asset_type_id' => $type->id]);
        Asset::factory()->excluded()->create(['team_id' => $team->id, 'asset_type_id' => $type->id]);
        Asset::factory()->inactive()->create(['team_id' => $team->id, 'asset_type_id' => $type->id]);

        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $quantity = fn (string $code) => (int) UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', UsageMeter::where('code', $code)->value('id'))
            ->sum('quantity');

        $this->assertSame(3, $quantity('monitored_assets'));
        $this->assertSame(3, $quantity('monitored_asset_days'));
        $this->assertSame('sum', UsageMeter::where('code', 'monitored_asset_days')->sole()->aggregation_type->value);
    }

    /**
     * Cobro por uso (decisión 2026-09-28): una fila por unidad y día local;
     * correr el cierre dos veces no duplica nada.
     */
    public function test_running_twice_the_same_day_records_a_single_asset_day_sample(): void
    {
        $this->seed(AssetMeterSeeder::class);

        $team = User::factory()->create()->currentTeam;
        Asset::factory()->count(2)->create(['team_id' => $team->id]);

        $this->artisan('assets:record-usage-meters')->assertSuccessful();
        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $this->assertSame(2, UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', UsageMeter::where('code', 'monitored_asset_days')->value('id'))
            ->count());
    }
}
