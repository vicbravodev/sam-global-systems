<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Data\BillingTermsData;
use App\Domains\Tenancy\Support\AssetDayPricing;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AssetDayPricingTest extends TestCase
{
    use AssertsSystemLog;

    private function terms(array $overrides = []): BillingTermsData
    {
        return new BillingTermsData(...array_merge([
            'unitPrice' => 450.0,
            'currency' => 'mxn',
            'includedAssets' => 100,
            'minBillableAssets' => 0,
            'aiFairUsePerAsset' => 60,
            'aiOverageUnitPrice' => 5.0,
            'messagingMarkupPercent' => null,
            'fxUsdRate' => 18.5,
            'volumeTiers' => [],
            'explicit' => true,
        ], $overrides));
    }

    public function test_asset_days_are_prorated_by_the_days_of_the_period(): void
    {
        // 100 tractos × 30 días = 3,000 tracto-días → 100 × 450.
        $line = AssetDayPricing::assetDayLine($this->terms(), 3000, 30, 100);

        $this->assertEqualsWithDelta(15.0, $line['daily_rate'], 0.000001);
        $this->assertEqualsWithDelta(45000.0, $line['amount'], 0.01);
        $this->assertSame(0, $line['overage']);
        $this->assertEqualsWithDelta(100.0, $line['average_assets'], 0.01);
    }

    public function test_a_unit_switched_on_mid_month_only_pays_its_days(): void
    {
        // Un solo tracto encendido 10 de 30 días.
        $line = AssetDayPricing::assetDayLine($this->terms(), 10, 30, null);

        $this->assertEqualsWithDelta(150.0, $line['amount'], 0.01);
    }

    public function test_days_above_the_cap_are_billed_at_the_same_rate_but_shown_as_extra(): void
    {
        // Tope 1: 2 tractos × 30 días = 60 tracto-días, 30 dentro y 30 extra.
        $line = AssetDayPricing::assetDayLine($this->terms(['includedAssets' => 1]), 60, 30, 1);

        $this->assertSame(30, $line['included']);
        $this->assertSame(30, $line['overage']);
        $this->assertEqualsWithDelta(450.0, $line['overage_cost'], 0.01);
        $this->assertEqualsWithDelta(900.0, $line['amount'], 0.01);
    }

    public function test_the_minimum_billable_fleet_applies_when_usage_is_below_it(): void
    {
        $line = AssetDayPricing::assetDayLine($this->terms(['minBillableAssets' => 10]), 90, 30, null);

        $this->assertSame(300, $line['billable_days']);
        $this->assertEqualsWithDelta(4500.0, $line['amount'], 0.01);
    }

    public function test_volume_tiers_pick_the_price_by_average_fleet_size(): void
    {
        $terms = $this->terms(['volumeTiers' => [
            ['from' => 1, 'to' => 25, 'unit_price' => 500],
            ['from' => 26, 'to' => 100, 'unit_price' => 450],
            ['from' => 101, 'to' => null, 'unit_price' => 400],
        ]]);

        $this->assertSame(500.0, AssetDayPricing::assetDayLine($terms, 20 * 30, 30, null)['unit_price']);
        $this->assertSame(450.0, AssetDayPricing::assetDayLine($terms, 60 * 30, 30, null)['unit_price']);
        $this->assertSame(400.0, AssetDayPricing::assetDayLine($terms, 250 * 30, 30, null)['unit_price']);

        // El escalón explicado es el mismo que decide el precio.
        foreach ([[20.0, 0, 1, 25], [59.4, 1, 26, 100], [250.0, 2, 101, null]] as [$average, $index, $from, $to]) {
            $explained = $terms->explainUnitPriceFor($average);

            $this->assertSame('volume_tier', $explained['source']);
            $this->assertSame($index, $explained['tier_index']);
            $this->assertSame($from, $explained['tier_from']);
            $this->assertSame($to, $explained['tier_to']);
            $this->assertSame(3, $explained['tiers_count']);
            $this->assertSame((int) ceil($average), $explained['tier_assets']);
            $this->assertSame($terms->unitPriceFor($average), $explained['unit_price']);
        }

        $flat = $this->terms()->explainUnitPriceFor(42.5);
        $this->assertSame('flat_unit_price', $flat['source']);
        $this->assertNull($flat['tier_index']);
        $this->assertSame(0, $flat['tiers_count']);
        $this->assertSame(43, $flat['tier_assets']);
        $this->assertSame(450.0, $flat['unit_price']);
    }

    public function test_the_loggable_line_drops_only_the_meter_name(): void
    {
        $line = AssetDayPricing::assetDayLine($this->terms(), 3000, 30, 100);
        $loggable = AssetDayPricing::loggable($line);

        $this->assertArrayNotHasKey('meter_name', $loggable);
        unset($line['meter_name']);
        $this->assertSame($line, $loggable);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_ai_fair_use_pools_evaluations_by_average_fleet(): void
    {
        // 10 tractos × 60 = 600 incluidas; 650 consumidas → 50 × 5.
        $line = AssetDayPricing::aiLine($this->terms(), 650, 10.0);

        $this->assertSame(600, $line['included']);
        $this->assertSame(50, $line['overage']);
        $this->assertEqualsWithDelta(250.0, $line['amount'], 0.01);
    }

    public function test_messaging_cost_is_marked_up_and_converted_to_the_tenant_currency(): void
    {
        // 1 USD de Twilio + 30 % = 1.30 USD → × 18.5 = 24.05 MXN.
        $line = AssetDayPricing::messagingLine($this->terms(), 1_000_000, 30.0, 'messaging_cost_micros', 'Twilio');

        $this->assertEqualsWithDelta(1.3, $line['charged_usd'], 0.0001);
        $this->assertEqualsWithDelta(24.05, $line['amount'], 0.01);

        $usd = AssetDayPricing::messagingLine($this->terms(['currency' => 'usd']), 1_000_000, 30.0, 'messaging_cost_micros', 'Twilio');
        $this->assertEqualsWithDelta(1.3, $usd['amount'], 0.01);
    }
}
