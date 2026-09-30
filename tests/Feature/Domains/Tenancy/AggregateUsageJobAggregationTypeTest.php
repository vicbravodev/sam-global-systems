<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Events\UsageUpdatedBroadcast;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Gauge meters (aggregation_type = max) are sampled once a day by
 * `assets:record-usage-meters`. Summing those samples turned 241 monitored
 * assets into ~6,748 for the month: a false overage and a false
 * UsageLimitExceeded. The period counter must honour the meter type.
 */
class AggregateUsageJobAggregationTypeTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private Team $team;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 9, 15)->setTime(12, 0));

        $this->team = Team::factory()->create();
        $this->plan = Plan::factory()->create();

        Subscription::factory()->create([
            'team_id' => $this->team->id,
            'plan_id' => $this->plan->id,
            'status' => SubscriptionStatus::Active,
        ]);
    }

    public function test_max_meter_counter_uses_the_peak_daily_sample_not_the_sum(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $meter = $this->meter('monitored_assets', AggregationType::Max, included: 250);

        foreach ([1 => 238, 2 => 241, 3 => 240] as $day => $quantity) {
            $this->sample($meter, $quantity, now()->setDate(2026, 9, $day)->setTime(0, 5));
        }

        (new AggregateUsageJob($this->team->id))->handle();

        $counter = $this->counterFor($meter, '2026-09-01');

        $this->assertSame(241, $counter->consumed_value);
        $this->assertSame(0, $counter->overage_value);
        Event::assertNotDispatched(UsageLimitExceeded::class);

        $computed = $this->assertSystemLogged('billing.overage.computed', fn (array $c) => $c['input']['meter_code'] === 'monitored_assets');
        $this->assertSame('max', $computed['calc']['aggregation_type']);
        $this->assertSame(241, $computed['calc']['consumed']);
        $this->assertSame(250, $computed['calc']['included']);
        $this->assertSame(0, $computed['result']['overage']);
        $this->assertFalse($computed['calc']['first_crossing']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_sum_meter_counter_still_accumulates(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $meter = $this->meter('ai_calls', AggregationType::Sum, included: 1000);

        foreach ([1 => 10, 2 => 25, 3 => 15] as $day => $quantity) {
            $this->sample($meter, $quantity, now()->setDate(2026, 9, $day)->setTime(9, 0));
        }

        (new AggregateUsageJob($this->team->id))->handle();

        $this->assertSame(50, $this->counterFor($meter, '2026-09-01')->consumed_value);
    }

    public function test_past_month_can_be_closed_without_live_alerts(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $meter = $this->meter('ai_calls', AggregationType::Sum, included: 10);

        $this->sample($meter, 40, now()->setDate(2026, 8, 31)->setTime(23, 30));
        $this->sample($meter, 5, now()->setDate(2026, 9, 2)->setTime(9, 0));

        (new AggregateUsageJob($this->team->id, '2026-08-01'))->handle();

        $august = $this->counterFor($meter, '2026-08-01');
        $this->assertSame(40, $august->consumed_value);
        $this->assertSame(30, $august->overage_value);
        $this->assertSame('2026-08-31', $august->period_end->toDateString());

        $this->assertDatabaseMissing('tenant_usage_counters', [
            'team_id' => $this->team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => '2026-09-01',
        ]);

        Event::assertNotDispatched(UsageLimitExceeded::class);
        Event::assertNotDispatched(UsageUpdatedBroadcast::class);

        // Cierre de un mes pasado: hay excedente, pero no es un primer cruce en vivo.
        $computed = $this->assertSystemLogged('billing.overage.computed', fn (array $c) => $c['input']['meter_code'] === 'ai_calls');
        $this->assertSame('2026-08-01', $computed['input']['period_start']);
        $this->assertTrue($computed['calc']['closed_period']);
        $this->assertSame(30, $computed['result']['overage']);
        $this->assertFalse($computed['calc']['first_crossing']);
        $this->assertFalse($computed['result']['limit_event_dispatched']);
        $this->assertFalse($computed['result']['broadcast_dispatched']);
        $this->assertTrue($this->assertSystemLogged('billing.aggregate.completed')['calc']['closed_period']);
    }

    private function meter(string $code, AggregationType $type, int $included): UsageMeter
    {
        $meter = UsageMeter::factory()->create(['code' => $code, 'aggregation_type' => $type]);

        BillingRate::factory()->create([
            'plan_id' => $this->plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => $included,
        ]);

        return $meter;
    }

    private function sample(UsageMeter $meter, int $quantity, mixed $occurredAt): void
    {
        UsageEvent::factory()->create([
            'team_id' => $this->team->id,
            'usage_meter_id' => $meter->id,
            'quantity' => $quantity,
            'occurred_at' => $occurredAt,
            'billing_period_key' => $occurredAt->format('Y-m'),
        ]);
    }

    private function counterFor(UsageMeter $meter, string $periodStart): TenantUsageCounter
    {
        return TenantUsageCounter::query()
            ->where('team_id', $this->team->id)
            ->where('usage_meter_id', $meter->id)
            ->whereDate('period_start', $periodStart)
            ->firstOrFail();
    }
}
