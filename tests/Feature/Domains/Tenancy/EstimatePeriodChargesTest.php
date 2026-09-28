<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Actions\EstimatePeriodCharges;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class EstimatePeriodChargesTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    public function test_it_projects_the_month_from_asset_days_so_far_and_units_monitored_now(): void
    {
        $team = Team::factory()->create();
        TenantBillingTerms::factory()->create([
            'team_id' => $team->id,
            'unit_price' => 300,
            'included_assets' => 2,
            'ai_fair_use_per_asset' => 10,
            'ai_overage_unit_price' => 2,
        ]);

        $meter = UsageMeter::query()->where('code', 'monitored_asset_days')->sole();
        $ai = UsageMeter::factory()->create(['code' => 'ai_calls']);

        // Septiembre 2026: 30 días. Dos muestras de 2 tractos (días 1 y 2).
        foreach (['2026-09-01', '2026-09-02'] as $day) {
            UsageEvent::factory()->create([
                'team_id' => $team->id,
                'usage_meter_id' => $meter->id,
                'quantity' => 2,
                'occurred_at' => "{$day} 00:05:00",
            ]);
        }
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $ai->id,
            'quantity' => 100,
            'occurred_at' => '2026-09-02 10:00:00',
        ]);

        // Hoy vigila 3 (una por encima del tope).
        Asset::factory()->count(3)->create(['team_id' => $team->id]);
        Asset::factory()->pendingMonitoring()->create(['team_id' => $team->id]);

        $estimate = app(EstimatePeriodCharges::class)->execute($team->id, CarbonImmutable::parse('2026-09-03'));

        $this->assertSame('mxn', $estimate['currency']);
        $this->assertSame(30, $estimate['daysInPeriod']);
        $this->assertSame(2, $estimate['daysRecorded']);
        $this->assertSame(4, $estimate['assetDays']);
        $this->assertSame(3, $estimate['monitoredNow']);
        $this->assertSame(2, $estimate['cap']);
        $this->assertTrue($estimate['overCap']);
        // 4 tracto-días a 300/30 = 10 por día.
        $this->assertEqualsWithDelta(40.0, $estimate['assetsToDate'], 0.01);
        // 4 + 3 × 28 días restantes = 88 tracto-días → 880.
        $this->assertSame(88, $estimate['projectedAssetDays']);
        $this->assertEqualsWithDelta(880.0, $estimate['assetsProjected'], 0.01);
        // Uso justo proyectado: promedio 88/30 ≈ 2.93 × 10 = 29 incluidas; 71 extra × 2.
        $this->assertSame(29, $estimate['aiIncluded']);
        $this->assertSame(71, $estimate['aiOverage']);
        $this->assertEqualsWithDelta(142.0, $estimate['aiProjected'], 0.01);
        $this->assertEqualsWithDelta(880.0 + 142.0, $estimate['totalProjected'], 0.01);
    }

    public function test_the_estimate_only_reads_the_tenants_own_usage_and_fleet(): void
    {
        $victim = Team::factory()->create();
        $actor = Team::factory()->create();
        $meter = UsageMeter::query()->where('code', 'monitored_asset_days')->sole();

        Asset::factory()->count(5)->create(['team_id' => $victim->id]);
        UsageEvent::factory()->create([
            'team_id' => $victim->id,
            'usage_meter_id' => $meter->id,
            'quantity' => 50,
            'occurred_at' => now()->startOfMonth()->addHours(1),
        ]);
        Asset::factory()->create(['team_id' => $actor->id]);

        $estimate = $this->assertNoTenantLeak(
            $actor,
            fn () => app(EstimatePeriodCharges::class)->execute($actor->id),
        );

        $this->assertSame(0, $estimate['assetDays']);
        $this->assertSame(1, $estimate['monitoredNow']);
    }

    /**
     * Transparencia (decisión 2026-09-28): el cliente ve el cierre de cada
     * día — tracto-días, emergencias de unidades no vigiladas e importe —,
     * con una fila por unidad y día contada como día, no como fila.
     */
    public function test_it_lists_a_daily_close_per_local_day(): void
    {
        $team = Team::factory()->create();
        TenantBillingTerms::factory()->create(['team_id' => $team->id, 'unit_price' => 300]);

        $days = UsageMeter::query()->where('code', 'monitored_asset_days')->sole();
        $emergencies = UsageMeter::query()->where('code', 'unmonitored_emergency_asset_days')->sole();

        // 1 de septiembre: 2 unidades (una fila por unidad) + una emergencia.
        UsageEvent::factory()->count(2)->create([
            'team_id' => $team->id,
            'usage_meter_id' => $days->id,
            'quantity' => 1,
            'occurred_at' => '2026-09-01 18:00:00',
        ]);
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $emergencies->id,
            'quantity' => 1,
            'occurred_at' => '2026-09-01 18:00:00',
        ]);
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $days->id,
            'quantity' => 1,
            'occurred_at' => '2026-09-02 18:00:00',
        ]);

        $estimate = app(EstimatePeriodCharges::class)->execute($team->id, CarbonImmutable::parse('2026-09-03'));

        $this->assertSame(2, $estimate['daysRecorded']);
        $this->assertSame(1, $estimate['unmonitoredEmergencyDays']);
        $this->assertSame(['2026-09-02', '2026-09-01'], array_column($estimate['dailyCloses'], 'date'));

        $first = $estimate['dailyCloses'][1];
        $this->assertSame(2, $first['assetDays']);
        $this->assertSame(1, $first['emergencyDays']);
        // 300 / 30 = 10 por día → 2 × 10 + 1 × 11 = 31.
        $this->assertSame(31.0, $first['amount']);
    }
}
