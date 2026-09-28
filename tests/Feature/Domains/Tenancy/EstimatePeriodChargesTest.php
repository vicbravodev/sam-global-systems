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
        // La IA extra "a hoy" es la ya comprometida contra la bolsa del mes,
        // no contra lo acumulado: no aparece un cargo que el cierre borraría.
        $this->assertEqualsWithDelta(142.0, $estimate['aiToDate'], 0.01);
        $this->assertEqualsWithDelta(40.0 + 142.0, $estimate['totalToDate'], 0.01);
        // El tope suave: 1 unidad arriba de 2 × 28 días restantes = 28 tracto-días extra.
        $this->assertSame(28, $estimate['projectedAssetDaysExtra']);
        $this->assertEqualsWithDelta(280.0, $estimate['assetsExtraProjected'], 0.01);
        $this->assertSame(0, $estimate['assetDaysExtra']);
        $this->assertSame(3, $estimate['daysElapsed']);
        $this->assertSame(28, $estimate['remainingDays']);
    }

    public function test_past_days_without_a_sample_are_not_projected(): void
    {
        // Alta a mitad de mes (o muestra nocturna caída): los días ya pasados
        // sin muestra no se facturan, así que tampoco se proyectan.
        $team = Team::factory()->create();
        TenantBillingTerms::factory()->create([
            'team_id' => $team->id,
            'unit_price' => 300,
            'included_assets' => null,
            'ai_fair_use_per_asset' => 60,
            'ai_overage_unit_price' => 5,
        ]);
        $ai = UsageMeter::factory()->create(['code' => 'ai_calls']);
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $ai->id,
            'quantity' => 100,
            'occurred_at' => '2026-09-10 10:00:00',
        ]);
        Asset::factory()->count(3)->create(['team_id' => $team->id]);

        $estimate = app(EstimatePeriodCharges::class)->execute($team->id, CarbonImmutable::parse('2026-09-28'));

        $this->assertSame(0, $estimate['assetDays']);
        $this->assertSame(0, $estimate['daysRecorded']);
        $this->assertSame(28, $estimate['daysElapsed']);
        // Hoy (sin muestra aún) + 29 + 30.
        $this->assertSame(3, $estimate['remainingDays']);
        $this->assertSame(9, $estimate['projectedAssetDays']);
        $this->assertEqualsWithDelta(90.0, $estimate['assetsProjected'], 0.01);
        // Bolsa: 60 × (9/30 = 0.3) = 18 incluidas; 82 extra × 5 = 410, igual a hoy y al cierre.
        $this->assertSame(18, $estimate['aiIncluded']);
        $this->assertEqualsWithDelta(410.0, $estimate['aiToDate'], 0.01);
        $this->assertEqualsWithDelta(410.0, $estimate['aiProjected'], 0.01);
        $this->assertEqualsWithDelta(410.0, $estimate['totalToDate'], 0.01);
    }

    public function test_today_is_not_projected_twice_once_its_sample_exists(): void
    {
        $team = Team::factory()->create();
        TenantBillingTerms::factory()->create(['team_id' => $team->id, 'unit_price' => 300]);
        $meter = UsageMeter::query()->where('code', 'monitored_asset_days')->sole();
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'quantity' => 2,
            'occurred_at' => '2026-09-28 00:00:05',
        ]);
        Asset::factory()->count(2)->create(['team_id' => $team->id]);

        $estimate = app(EstimatePeriodCharges::class)->execute($team->id, CarbonImmutable::parse('2026-09-28 15:00'));

        $this->assertSame(2, $estimate['remainingDays']);
        $this->assertSame(2 + 2 * 2, $estimate['projectedAssetDays']);
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
}
