<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Jobs\GenerateInvoiceSnapshotJob;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: cada unidad-día de una emergencia atendida en una
 * unidad no vigilada se factura a la tarifa diaria del tracto + 10%.
 */
class UnmonitoredEmergencyInvoiceLineTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private function counter(Team $team, string $code, int $consumed): void
    {
        TenantUsageCounter::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => UsageMeter::query()->where('code', $code)->value('id'),
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'consumed_value' => $consumed,
            'included_value' => 0,
            'overage_value' => 0,
        ]);
    }

    public function test_the_invoice_charges_the_surcharged_emergency_days(): void
    {
        $team = Team::factory()->create();
        TenantBillingTerms::factory()->create(['team_id' => $team->id, 'unit_price' => 300]);

        $this->counter($team, AssetDayPricing::METER_CODE, 30);
        $this->counter($team, AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE, 2);

        (new GenerateInvoiceSnapshotJob($team->id, '2026-09-01', '2026-09-30'))->handle();

        $invoice = InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $line = collect($invoice->breakdown_json)->firstWhere('meter_code', AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE);

        // 300 / 30 = 10 por día; +10% = 11; 2 días = 22.
        $this->assertNotNull($line);
        $this->assertSame(22.0, (float) $line['amount']);
        $this->assertSame(322.0, (float) $invoice->subtotal);

        // El recargo parte de la tarifa diaria YA redondeada a 6 decimales.
        $c = $this->assertSystemLogged(
            'billing.invoice_line.calculated',
            fn (array $c) => $c['calc']['billing_model'] === 'asset_day_surcharge',
        );
        $this->assertSame(10.0, $c['calc']['daily_rate']);
        $this->assertSame(10.0, $c['calc']['surcharge_percent']);
        $this->assertSame(2, $c['calc']['consumed']);
        $this->assertTrue($c['calc']['counter_found']);
        $this->assertSame('subtotal', $c['result']['counts_towards']);
        $this->assertStringContainsString('round(daily_rate * (1 + surcharge_percent / 100), 6)', $c['calc']['formula']);

        $recomputed = round($c['calc']['consumed'] * round($c['calc']['daily_rate'] * (1 + $c['calc']['surcharge_percent'] / 100), 6), 2);
        $this->assertSame(22.0, $recomputed);
        $this->assertSame($recomputed, $c['result']['amount']);
        // breakdown_json devuelve 22 (int) al decodificar: se compara al centavo.
        $this->assertSame(number_format($recomputed, 2, '.', ''), number_format($line['amount'], 2, '.', ''));

        $generated = $this->assertSystemLogged('billing.invoice.generated');
        $this->assertSame([300.0, 22.0], $generated['calc']['subtotal_terms']);
        $this->assertSame(number_format(round(array_sum($generated['calc']['subtotal_terms']), 2), 2, '.', ''), $invoice->subtotal);
        $this->assertSame('322.00', $generated['result']['subtotal']);

        $sources = $this->assertSystemLogged('billing.terms.resolved')['calc']['sources'];
        $this->assertSame('tenant', $sources['unit_price']);

        $this->assertTrue($this->assertSystemLogged('billing.asset_day.calculated')['calc']['counter_found']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_no_emergency_line_without_emergencies(): void
    {
        $team = Team::factory()->create();
        TenantBillingTerms::factory()->create(['team_id' => $team->id, 'unit_price' => 300]);
        $this->counter($team, AssetDayPricing::METER_CODE, 30);

        (new GenerateInvoiceSnapshotJob($team->id, '2026-09-01', '2026-09-30'))->handle();

        $invoice = InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $this->assertNull(collect($invoice->breakdown_json)->firstWhere('meter_code', AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE));
    }
}
