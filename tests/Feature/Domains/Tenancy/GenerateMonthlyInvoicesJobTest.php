<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Jobs\GenerateInvoiceSnapshotJob;
use App\Domains\Tenancy\Jobs\GenerateMonthlyInvoicesJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateMonthlyInvoicesJobTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private UsageMeter $messages;

    private UsageMeter $assets;

    private UsageMeter $assetDays;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(5, 0));

        $this->plan = Plan::factory()->create(['base_price' => 100, 'currency' => 'usd']);

        $this->messages = UsageMeter::factory()->create(['code' => 'messages', 'aggregation_type' => AggregationType::Sum]);
        $this->assets = UsageMeter::factory()->create(['code' => 'monitored_assets', 'aggregation_type' => AggregationType::Max]);
        // Sembrado por migración (insertOrIgnore): base del cobro por tracto-día.
        $this->assetDays = UsageMeter::query()->where('code', 'monitored_asset_days')->sole();
        config()->set('billing.unit_price', 450);
        config()->set('billing.min_billable_assets', 0);
        config()->set('billing.volume_tiers', []);

        BillingRate::factory()->create([
            'plan_id' => $this->plan->id,
            'usage_meter_id' => $this->messages->id,
            'included_quantity' => 10,
            'overage_unit_price' => 1.00,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $this->plan->id,
            'usage_meter_id' => $this->assets->id,
            'included_quantity' => 250,
            'overage_unit_price' => 5.00,
        ]);
    }

    public function test_it_closes_and_invoices_the_previous_month_for_operational_tenants(): void
    {
        $team = $this->tenant(SubscriptionStatus::Active, currency: 'mxn');

        // Late usage on the last day of September: never seen by the daily
        // aggregation, so the counter must be recomputed before invoicing.
        $this->usage($team, $this->messages, 12, '2026-09-30 23:40:00');
        $this->usage($team, $this->messages, 3, '2026-09-10 10:00:00');
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $i => $day) {
            $this->usage($team, $this->assets, 239 + $i, "{$day} 00:05:00");
            $this->usage($team, $this->assetDays, 239 + $i, "{$day} 00:05:00");
        }

        // October usage must not leak into the September invoice.
        $this->usage($team, $this->messages, 50, '2026-10-01 00:30:00');

        (new GenerateMonthlyInvoicesJob)->handle();

        $invoice = InvoiceSnapshot::query()->where('team_id', $team->id)->sole();

        $this->assertSame('2026-09-01', $invoice->period_start->toDateString());
        $this->assertSame('2026-09-30', $invoice->period_end->toDateString());
        // La moneda es la de los términos del tenant (default de plataforma),
        // nunca la del plan ni la del team.
        $this->assertSame(config('billing.currency'), $invoice->currency);

        $lines = collect($invoice->breakdown_json)->keyBy('meter_code');
        $this->assertEquals(15, $lines['messages']['consumed']);
        $this->assertEquals(5, $lines['messages']['overage']);
        // El gauge `monitored_assets` del plan no se repite en la factura: el
        // tope vive en la línea de tracto-día.
        $this->assertArrayNotHasKey('monitored_assets', $lines->all());

        // Tracto-día: 3 muestras de 239/240/241 = 720 tracto-días en un mes
        // de 30 días a 450/mes → 720 × 15 = 10,800; más 5 de excedente de
        // mensajes a 1.00. El plan no aporta precio base.
        $this->assertEquals(720, $lines['monitored_asset_days']['consumed']);
        $this->assertEquals(30, $lines['monitored_asset_days']['days_in_period']);
        $this->assertEqualsWithDelta(720 * (450 / 30), $lines['monitored_asset_days']['amount'], 0.01);
        $this->assertEqualsWithDelta(720 * (450 / 30), (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(720 * (450 / 30) + 5.0, (float) $invoice->total, 0.01);
    }

    public function test_tenants_without_an_operational_subscription_are_skipped(): void
    {
        $active = $this->tenant(SubscriptionStatus::PastDue);
        $canceled = $this->tenant(SubscriptionStatus::Canceled);
        $suspended = $this->tenant(SubscriptionStatus::Suspended);

        (new GenerateMonthlyInvoicesJob)->handle();

        $this->assertSame(1, InvoiceSnapshot::query()->where('team_id', $active->id)->count());
        $this->assertSame(0, InvoiceSnapshot::query()->whereIn('team_id', [$canceled->id, $suspended->id])->count());
    }

    public function test_each_invoice_only_contains_its_own_tenant_usage(): void
    {
        $teamA = $this->tenant(SubscriptionStatus::Active);
        $teamB = $this->tenant(SubscriptionStatus::Active);

        $this->usage($teamA, $this->messages, 11, '2026-09-15 12:00:00');
        $this->usage($teamB, $this->messages, 40, '2026-09-15 12:00:00');

        (new GenerateMonthlyInvoicesJob)->handle();

        $consumed = fn (Team $team) => collect(InvoiceSnapshot::query()->where('team_id', $team->id)->sole()->breakdown_json)
            ->firstWhere('meter_code', 'messages')['consumed'];

        $this->assertEquals(11, $consumed($teamA));
        $this->assertEquals(40, $consumed($teamB));
    }

    public function test_single_invoice_uses_the_tenant_billing_terms_currency(): void
    {
        $team = $this->tenant(SubscriptionStatus::Active, currency: 'mxn');
        TenantBillingTerms::factory()->create(['team_id' => $team->id, 'currency' => 'USD']);

        (new GenerateInvoiceSnapshotJob($team->id))->handle();

        $invoice = InvoiceSnapshot::query()->where('team_id', $team->id)->sole();
        $this->assertSame('usd', $invoice->currency);
        $this->assertSame('usd', collect($invoice->breakdown_json)->firstWhere('meter_code', '_terms')['terms']['currency']);
    }

    public function test_it_is_scheduled_monthly_on_the_first(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event instanceof CallbackEvent
                && str_contains((string) $event->description, GenerateMonthlyInvoicesJob::class));

        $this->assertNotNull($event, 'GenerateMonthlyInvoicesJob must be scheduled');
        $this->assertSame('0 5 1 * *', $event->expression);
        $this->assertTrue($event->onOneServer);
    }

    private function tenant(SubscriptionStatus $status, string $currency = 'usd'): Team
    {
        $team = Team::factory()->create(['currency' => $currency]);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $this->plan->id,
            'status' => $status,
        ]);

        return $team;
    }

    private function usage(Team $team, UsageMeter $meter, int $quantity, string $occurredAt): void
    {
        UsageEvent::factory()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'quantity' => $quantity,
            'occurred_at' => $occurredAt,
            'billing_period_key' => substr($occurredAt, 0, 7),
        ]);
    }
}
