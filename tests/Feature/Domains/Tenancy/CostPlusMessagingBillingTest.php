<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\BillingModel;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Jobs\GenerateInvoiceSnapshotJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Twilio messaging is billed at Twilio's real cost plus a margin: the meter
 * `messaging_cost_micros` accumulates provider cost in micro-USD and the
 * invoice line is cost × (1 + markup / 100), with no included quota.
 */
class CostPlusMessagingBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_bill_messaging_cost_plus_thirty_percent(): void
    {
        $this->seed(NotificationMeterSeeder::class);
        $this->seed(PlanSeeder::class);

        $meter = UsageMeter::query()->where('code', 'messaging_cost_micros')->sole();
        $this->assertSame('usd_micros', $meter->unit);

        $rates = BillingRate::query()->where('usage_meter_id', $meter->id)->get();
        $this->assertCount(Plan::query()->count(), $rates);

        foreach ($rates as $rate) {
            $this->assertSame(BillingModel::CostPlus, $rate->billing_model);
            $this->assertSame('30.00', $rate->markup_percent);
            $this->assertSame(0, $rate->included_quantity);
        }
    }

    public function test_invoice_line_is_provider_cost_plus_markup(): void
    {
        [$team, $meter] = $this->subscribedTeamWithMessagingRate(markup: 30);

        // $12.345678 of Twilio cost this period.
        TenantUsageCounter::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'consumed_value' => 12_345_678,
        ]);

        (new GenerateInvoiceSnapshotJob($team->id))->handle();

        $snapshot = InvoiceSnapshot::withoutGlobalScopes()->where('team_id', $team->id)->sole();
        $line = collect($snapshot->breakdown_json)->firstWhere('meter_code', 'messaging_cost_micros');

        $this->assertSame('cost_plus', $line['billing_model']);
        $this->assertEqualsWithDelta(12.345678, $line['provider_cost'], 0.000001);
        $this->assertEqualsWithDelta(30.0, $line['markup_percent'], 0.001);
        $this->assertEqualsWithDelta(16.0494, $line['charged_usd'], 0.0001);
        // Términos de plataforma en MXN: el costo USD se traslada con el tipo
        // de cambio configurado y el plan ya no aporta precio base.
        $fx = (float) config('billing.fx_usd_rate');
        $this->assertEqualsWithDelta(16.0494 * $fx, $line['overage_cost'], 0.01);
        $this->assertSame(config('billing.currency'), $snapshot->currency);
        $this->assertEqualsWithDelta(16.0494 * $fx, (float) $snapshot->total, 0.01);
    }

    public function test_billing_page_shows_messaging_as_money_not_micros(): void
    {
        $this->seed(AccessSeeder::class);
        $user = User::factory()->create();
        [$team, $meter] = $this->subscribedTeamWithMessagingRate(markup: 30, team: $user->currentTeam);

        TenantUsageCounter::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'consumed_value' => 1_000_000,
        ]);

        $this->actingAs($user)
            ->get(route('billing.show', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('billing/index')
                ->where('usage.0.meterCode', 'messaging_cost_micros')
                ->where('usage.0.amount', 1.3));
    }

    /**
     * @return array{0: Team, 1: UsageMeter}
     */
    private function subscribedTeamWithMessagingRate(float $markup, ?Team $team = null): array
    {
        $team ??= Team::factory()->create();
        $plan = Plan::factory()->create(['base_price' => 99]);
        $meter = UsageMeter::query()->where('code', 'messaging_cost_micros')->sole();

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 0,
            'overage_unit_price' => 0,
            'billing_model' => BillingModel::CostPlus,
            'markup_percent' => $markup,
        ]);

        return [$team, $meter];
    }
}
