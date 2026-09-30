<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Jobs\GenerateInvoiceSnapshotJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Models\Team;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class InvoiceSnapshotTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_invoice_snapshot_contains_per_meter_breakdown(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::factory()->create(['base_price' => 99.00]);

        $meterA = UsageMeter::factory()->create(['code' => 'api_requests', 'name' => 'API Requests']);
        $meterB = UsageMeter::factory()->create(['code' => 'ai_calls', 'name' => 'AI Calls']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meterA->id,
            'included_quantity' => 1000,
            'overage_unit_price' => 0.01,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meterB->id,
            'included_quantity' => 50,
            'overage_unit_price' => 0.50,
        ]);

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meterA->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'consumed_value' => 1200,
            'included_value' => 1000,
            'overage_value' => 200,
        ]);

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meterB->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'consumed_value' => 75,
            'included_value' => 50,
            'overage_value' => 25,
        ]);

        $job = new GenerateInvoiceSnapshotJob($team->id, $periodStart, $periodEnd);
        $job->handle();

        $snapshot = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereDate('period_start', $periodStart)
            ->first();

        $this->assertNotNull($snapshot, 'Invoice snapshot should be created for the billing period');

        $breakdown = $snapshot->breakdown_json;

        $this->assertIsArray($breakdown, 'Breakdown should be a JSON array');
        // Tracto-día + IA (uso justo) + Twilio (meter sembrado por migración) +
        // plan (api_requests) + términos. ai_calls se factura por uso justo del
        // tenant, no por la tarifa del plan.
        $this->assertEqualsCanonicalizing(
            ['monitored_asset_days', 'ai_calls', 'messaging_cost_micros', 'api_requests', '_terms'],
            collect($breakdown)->pluck('meter_code')->all(),
        );

        $apiEntry = collect($breakdown)->firstWhere('meter_code', 'api_requests');
        $this->assertNotNull($apiEntry, 'Breakdown should include api_requests meter entry');
        $this->assertEquals(1200, $apiEntry['consumed'], 'API requests consumed should be 1200');
        $this->assertEquals(1000, $apiEntry['included'], 'API requests included should be 1000');
        $this->assertEquals(200, $apiEntry['overage'], 'API requests overage should be 200');

        $aiEntry = collect($breakdown)->firstWhere('meter_code', 'ai_calls');
        $this->assertNotNull($aiEntry, 'Breakdown should include ai_calls meter entry');
        $this->assertEquals(75, $aiEntry['consumed'], 'AI calls consumed should be 75');
        $this->assertSame('fair_use', $aiEntry['billing_model']);
        // Sin tracto-días vigilados el uso justo incluido es 0: las 75
        // evaluaciones son excedente al precio de plataforma.
        $this->assertEquals(75, $aiEntry['overage']);

        $expectedOverageTotal = (200 * 0.01) + (75 * (float) config('billing.ai_overage_unit_price'));
        $this->assertEqualsWithDelta(
            $expectedOverageTotal,
            (float) $snapshot->overage_total,
            0.01,
            'Invoice overage total should sum all meter overage costs',
        );

        // El plan ya no aporta precio base: sin tracto-días el subtotal es 0.
        $this->assertEquals(0.0, (float) $snapshot->subtotal);
        $this->assertEqualsWithDelta(
            $expectedOverageTotal,
            (float) $snapshot->total,
            0.01,
            'Invoice total is the asset-day line plus overage total',
        );

        // Log narrativo: términos con su fuente, escalón, línea de tracto-día,
        // una línea por cada importe añadido y la factura recomputable al centavo.
        $terms = $this->assertSystemLogged('billing.terms.resolved', fn (array $c) => $c['input']['team_id'] === $team->id);
        $this->assertSame('invoice', $terms['input']['stage']);
        $this->assertSame('config', $terms['calc']['sources']['unit_price']);
        $this->assertSame('unset', $terms['calc']['sources']['included_assets']);
        $this->assertSame((float) config('billing.unit_price'), $terms['calc']['values']['unit_price']);

        $limit = $this->assertSystemLogged('billing.asset_limit.resolved');
        // Este fixture no siembra el meter `monitored_assets`: sin tope y degradado.
        $this->assertSame('degraded', $limit['outcome']);
        $this->assertSame('meter_missing', $limit['reason']);
        $this->assertSame('none', $limit['calc']['source']);
        $this->assertSame('meter_missing', $limit['calc']['none_reason']);
        $this->assertNull($limit['result']['cap']);

        $assetLine = collect($breakdown)->firstWhere('meter_code', AssetDayPricing::METER_CODE);
        $tier = $this->assertSystemLogged('billing.tier.selected');
        $this->assertSame('flat_unit_price', $tier['calc']['source']);
        $this->assertEquals($assetLine['unit_price'], $tier['result']['unit_price']);
        $this->assertSame($tier['calc']['unit_price'], $tier['result']['unit_price']);

        $this->assertInvoiceRecomputesToTheCent($snapshot);
        $this->assertSame(false, $this->assertSystemLogged('billing.asset_day.calculated')['calc']['counter_found']);

        $json = json_encode($this->systemLogEntries(), JSON_UNESCAPED_UNICODE);
        foreach ($breakdown as $line) {
            $this->assertStringNotContainsString((string) $line['meter_name'], $json);
        }
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_missing_counter_and_a_missing_meter_are_logged(): void
    {
        $team = Team::factory()->create();
        UsageMeter::query()->where('code', AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE)->delete();

        (new GenerateInvoiceSnapshotJob($team->id, '2026-09-01', '2026-09-30'))->handle();

        $invoice = InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->sole();

        // Sin contador del periodo: la factura lee 0, y el log lo dice.
        $assetDay = $this->assertSystemLogged('billing.asset_day.calculated');
        $this->assertFalse($assetDay['calc']['counter_found']);
        $this->assertSame(0, $assetDay['calc']['consumed']);

        $missing = $this->assertSystemLogged(
            'billing.meter.missing',
            fn (array $c) => ($c['input']['meter_code'] ?? null) === AssetDayPricing::UNMONITORED_EMERGENCY_METER_CODE,
        );
        $this->assertSame('meter_missing', $missing['reason']);
        $this->assertSame('invoice', $missing['input']['stage']);
        $this->assertSame($team->id, $missing['input']['team_id']);
        $this->assertSame('2026-09-01', $missing['input']['period_start']);

        $this->assertInvoiceRecomputesToTheCent($invoice);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_invoice_log_never_carries_bank_data(): void
    {
        config()->set('billing.transfer.clabe', '012180001234567891');
        config()->set('billing.transfer.beneficiary', 'Beneficiario Ejemplo SA');
        $team = Team::factory()->create();

        (new GenerateInvoiceSnapshotJob($team->id, '2026-09-01', '2026-09-30'))->handle();

        $this->assertSystemLogged('billing.invoice.generated');
        $json = json_encode($this->systemLogEntries(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('012180001234567891', $json);
        $this->assertStringNotContainsString('Beneficiario Ejemplo SA', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_lock_wait_timeout_is_logged_and_rethrown(): void
    {
        $team = Team::factory()->create();

        // Doble del lock (no de la DB): esperar los 30 s reales de block() no
        // cabe en la suite.
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        try {
            (new GenerateInvoiceSnapshotJob($team->id, '2026-09-01', '2026-09-30'))->handle();
            $this->fail('Se esperaba LockTimeoutException');
        } catch (LockTimeoutException) {
            // El job relanza la misma excepción: la cola reintenta como antes.
        }

        $c = $this->assertSystemLogged('billing.invoice.lock_timeout');
        $this->assertSame('degraded', $c['outcome']);
        $this->assertSame('lock_wait_exceeded', $c['reason']);
        $this->assertSame(120, $c['calc']['lock_seconds']);
        $this->assertSame(30, $c['calc']['wait_seconds']);
        $this->assertSame($team->id, $c['input']['team_id']);
        $this->assertSame('2026-09-30', $c['input']['period_end']);
        $this->assertSame(0, InvoiceSnapshot::withoutGlobalScopes()->count());
        $this->assertSystemNotLogged('billing.invoice.generated');
        $this->assertNoSensitiveDataLogged();
    }

    /**
     * Rehace cada línea desde su `calc` + fórmula, y subtotal / excedente /
     * total desde los términos registrados, contra el log y lo persistido.
     */
    private function assertInvoiceRecomputesToTheCent(InvoiceSnapshot $invoice): void
    {
        $cents = fn (float $x): string => number_format($x, 2, '.', '');
        $breakdown = collect($invoice->breakdown_json);
        $teamId = (int) $invoice->team_id;

        $assetDay = $this->assertSystemLogged('billing.asset_day.calculated', fn (array $c) => $c['input']['team_id'] === $teamId);
        $a = $assetDay['calc'];
        $persistedAssetLine = $breakdown->firstWhere('meter_code', AssetDayPricing::METER_CODE);
        $this->assertArrayNotHasKey('meter_name', $a);
        $this->assertSame($a['billable_days'], max($a['consumed'], $a['min_billable_assets'] * $a['days_in_period']));
        $amount = round($a['billable_days'] * ($a['unit_price'] / $a['days_in_period']), 2);
        $this->assertSame($cents($amount), $cents($assetDay['result']['amount']));
        $this->assertSame($cents($amount), $cents($persistedAssetLine['amount']));
        $this->assertSame($invoice->currency, $assetDay['result']['currency']);

        $lines = collect($this->systemLogEntries('billing.invoice_line.calculated'))
            ->pluck('context')
            ->filter(fn (array $c) => $c['input']['team_id'] === $teamId)
            ->values();

        // Una línea de log por línea de importe (todas menos tracto-día y términos).
        $this->assertEqualsCanonicalizing(
            $breakdown->pluck('meter_code')->reject(fn ($code) => in_array($code, [AssetDayPricing::METER_CODE, '_terms'], true))->values()->all(),
            $lines->pluck('calc.meter_code')->all(),
        );

        foreach ($lines as $line) {
            $calc = $line['calc'];
            $this->assertArrayNotHasKey('meter_name', $calc);
            $recomputed = $this->recomputeLine($calc);
            $persisted = $breakdown->firstWhere('meter_code', $calc['meter_code']);

            $this->assertSame($cents($recomputed), $cents($line['result']['amount']), $calc['meter_code']);
            $this->assertSame($cents($recomputed), $cents($persisted['amount']), $calc['meter_code']);
            $this->assertSame(
                $calc['billing_model'] === 'asset_day_surcharge' ? 'subtotal' : 'overage_total',
                $line['result']['counts_towards'],
            );
        }

        $generated = $this->assertSystemLogged('billing.invoice.generated', fn (array $c) => $c['input']['team_id'] === $teamId);
        $g = $generated['calc'];
        $subtotal = round(array_sum($g['subtotal_terms']), 2);
        $overage = round(array_sum($g['overage_terms']), 2);
        $total = round(array_sum($g['subtotal_terms']) + $overage, 2);

        $this->assertSame($cents($subtotal), $generated['result']['subtotal']);
        $this->assertSame($cents($subtotal), $invoice->subtotal);
        $this->assertSame($cents($overage), $generated['result']['overage_total']);
        $this->assertSame($cents($overage), $invoice->overage_total);
        $this->assertSame($cents($total), $generated['result']['total']);
        $this->assertSame($cents($total), $invoice->total);
        $this->assertSame($invoice->id, $generated['result']['invoice_id']);
        $this->assertSame($invoice->status->value, $generated['result']['status']);
        $this->assertSame($invoice->currency, $g['currency']);
        $this->assertSame(count($invoice->breakdown_json) - 1, $g['lines_count']);

        // Cada término es el importe de su línea, en el orden en que se suma.
        $this->assertSame($assetDay['result']['amount'], $g['subtotal_terms'][0]);
        $this->assertSame(
            $lines->filter(fn ($c) => $c['result']['counts_towards'] === 'overage_total')->pluck('result.amount')->values()->all(),
            $g['overage_terms'],
        );
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function recomputeLine(array $c): float
    {
        return match ($c['billing_model']) {
            'asset_day_surcharge' => round($c['consumed'] * round($c['daily_rate'] * (1 + $c['surcharge_percent'] / 100), 6), 2),
            'fair_use' => round(max(0, $c['consumed'] - (int) floor($c['fair_use_per_asset'] * $c['average_assets'])) * $c['overage_unit_price'], 2),
            'cost_plus' => (function () use ($c): float {
                $chargedUsd = round(round($c['consumed'] / 1e6, 6) * (1 + $c['markup_percent'] / 100), 4);
                $charged = round($chargedUsd * $c['fx_usd_rate'], 4);

                return round($charged, 2);
            })(),
            default => round(max(0, $c['consumed'] - $c['included']) * $c['overage_unit_price'], 2),
        };
    }

    public function test_duplicate_snapshot_for_same_period_is_not_created(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::factory()->create();

        $subscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertNotNull($subscription->id, 'Subscription should be created');

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        (new GenerateInvoiceSnapshotJob($team->id, $periodStart, $periodEnd))->handle();

        $firstCount = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->count();

        $this->assertEquals(1, $firstCount, 'First invocation should create one snapshot');

        (new GenerateInvoiceSnapshotJob($team->id, $periodStart, $periodEnd))->handle();

        $secondCount = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->count();

        $this->assertEquals(1, $secondCount, 'Second invocation should not create a duplicate snapshot');

        $skipped = $this->assertSystemLogged('billing.invoice.already_exists');
        $this->assertSame('skipped', $skipped['outcome']);
        $this->assertSame('period_already_invoiced', $skipped['reason']);
        $this->assertSame('invoice', $skipped['input']['stage']);
        $this->assertSame($team->id, $skipped['input']['team_id']);
        $this->assertSame($periodStart, $skipped['input']['period_start']);
        $this->assertCount(1, $this->systemLogEntries('billing.invoice.generated'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_database_rejects_a_second_invoice_for_the_same_period(): void
    {
        $team = Team::factory()->create();

        InvoiceSnapshot::factory()->create([
            'team_id' => $team->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        InvoiceSnapshot::factory()->create([
            'team_id' => $team->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
    }
}
