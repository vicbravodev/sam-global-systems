<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\InvoiceStatus;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * UI audit P1-2/P1-8: the billing page sends calendar dates (not UTC
 * instants), Spanish labels, which meters the plan actually invoices,
 * module features only, and which invoices accept a transfer receipt.
 */
class BillingPagePresentationTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->plan = Plan::factory()->create();

        Subscription::factory()->create([
            'team_id' => $this->team->id,
            'plan_id' => $this->plan->id,
            'status' => SubscriptionStatus::Active,
            'renews_at' => '2026-10-01 00:00:00',
        ]);
    }

    public function test_dates_are_calendar_dates_and_statuses_are_labelled(): void
    {
        InvoiceSnapshot::factory()->create([
            'team_id' => $this->team->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => InvoiceStatus::Invoiced,
        ]);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->component('billing/index')
            ->where('subscription.renewsAt', '2026-10-01')
            ->where('subscription.statusLabel', 'Activa')
            ->where('subscription.billingCycleLabel', 'Mensual')
            ->where('invoices.0.periodStart', '2026-08-01')
            ->where('invoices.0.periodEnd', '2026-08-31')
            ->where('invoices.0.statusLabel', 'Facturada'));
    }

    public function test_meters_without_a_plan_rate_are_flagged_as_not_billed(): void
    {
        $billed = UsageMeter::factory()->create(['code' => 'test_billed_meter']);
        $tracked = UsageMeter::factory()->create(['code' => 'test_tracked_meter']);

        BillingRate::factory()->create([
            'plan_id' => $this->plan->id,
            'usage_meter_id' => $billed->id,
            'overage_unit_price' => 0.01,
        ]);

        foreach ([$billed, $tracked] as $meter) {
            TenantUsageCounter::factory()->create([
                'team_id' => $this->team->id,
                'usage_meter_id' => $meter->id,
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'consumed_value' => 30,
                'included_value' => 0,
                'overage_value' => 30,
            ]);
        }

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->has('usage', 2)
            ->where('usage.0.meterCode', 'test_billed_meter')
            ->where('usage.0.billed', true)
            ->where('usage.0.overageCharged', true)
            ->where('usage.1.meterCode', 'test_tracked_meter')
            ->where('usage.1.billed', false)
            ->where('usage.1.overageCharged', false));
    }

    public function test_asset_day_meters_count_as_billed_without_a_plan_rate(): void
    {
        // Tracto-día model: asset-days, the unit cap and AI fair use are
        // invoiced through the tenant terms, not through BillingRate rows.
        foreach (['monitored_asset_days', 'monitored_assets', 'ai_calls'] as $code) {
            TenantUsageCounter::factory()->create([
                'team_id' => $this->team->id,
                'usage_meter_id' => UsageMeter::query()->where('code', $code)->value('id')
                    ?? UsageMeter::factory()->create(['code' => $code])->id,
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'consumed_value' => 10,
                'included_value' => 0,
                'overage_value' => 10,
            ]);
        }

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->has('usage', 3)
            ->where('usage', function ($usage): bool {
                $rows = collect($usage)->keyBy('meterCode');

                return $rows->every(fn (array $row): bool => $row['billed'] === true)
                    && $rows['monitored_asset_days']['overageCharged'] === false
                    && $rows['monitored_assets']['overageCharged'] === false;
            }));
    }

    public function test_per_channel_twilio_counts_point_to_the_messaging_line(): void
    {
        TenantUsageCounter::factory()->create([
            'team_id' => $this->team->id,
            'usage_meter_id' => UsageMeter::query()->where('code', 'sms_messages')->value('id')
                ?? UsageMeter::factory()->create(['code' => 'sms_messages'])->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'consumed_value' => 5,
        ]);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('usage.0.meterCode', 'sms_messages')
            ->where('usage.0.billed', false)
            ->where('usage.0.billedVia', 'messaging'));
    }

    public function test_features_list_excludes_meter_allowances(): void
    {
        // Per-meter allowances are stored as features keyed by meter code.
        $meter = UsageMeter::factory()->create(['code' => 'test_allowance_meter']);

        TenantFeature::factory()->create(['team_id' => $this->team->id, 'feature_key' => 'copilot']);
        TenantFeature::factory()->create(['team_id' => $this->team->id, 'feature_key' => $meter->code]);

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->has('features', 1)
            ->where('features.0.key', 'copilot')
            ->where('features.0.sourceLabel', 'Plan por defecto'));
    }

    public function test_only_invoices_awaiting_payment_accept_a_receipt(): void
    {
        $cases = [
            'invoiced' => [InvoiceStatus::Invoiced, now()->subMonth()->startOfMonth(), true],
            'closed_draft' => [InvoiceStatus::Draft, now()->subMonths(2)->startOfMonth(), true],
            'running_draft' => [InvoiceStatus::Draft, now()->startOfMonth(), false],
            'void' => [InvoiceStatus::Void, now()->subMonths(3)->startOfMonth(), false],
            'paid' => [InvoiceStatus::Paid, now()->subMonths(4)->startOfMonth(), false],
        ];

        $ids = [];

        foreach ($cases as $name => [$status, $start, $awaits]) {
            $ids[$name] = InvoiceSnapshot::factory()->create([
                'team_id' => $this->team->id,
                'status' => $status,
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
            ])->id;
        }

        $this->page()->assertInertia(function (Assert $page) use ($cases, $ids) {
            $invoices = collect($page->toArray()['props']['invoices'])->keyBy('id');

            foreach ($cases as $name => [, , $awaits]) {
                $this->assertSame($awaits, $invoices[$ids[$name]]['awaitsPayment'], $name);
            }
        });
    }

    public function test_billing_page_reads_no_other_tenant_data(): void
    {
        $other = User::factory()->create()->currentTeam;
        $meter = UsageMeter::factory()->create(['code' => 'test_billed_meter']);
        TenantUsageCounter::factory()->create([
            'team_id' => $other->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);
        TenantFeature::factory()->create(['team_id' => $other->id, 'feature_key' => 'copilot']);
        InvoiceSnapshot::factory()->create(['team_id' => $other->id]);

        $response = $this->assertNoTenantLeak($this->team, fn () => $this->page());

        $response->assertInertia(fn (Assert $page) => $page
            ->has('usage', 0)
            ->has('features', 0)
            ->has('invoices', 0));
    }

    private function page(): TestResponse
    {
        return $this->actingAs($this->user)
            ->get(route('billing.show', ['current_team' => $this->team->slug]))
            ->assertOk();
    }
}
