<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageDailyAggregate;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AggregateUsageJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_daily_aggregation_sums_usage_events_correctly(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'api_requests']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 99999,
        ]);

        $today = now()->startOfDay();

        UsageEvent::withoutGlobalScopes()->insert([
            [
                'team_id' => $team->id,
                'usage_meter_id' => $meter->id,
                'event_key' => 'evt-1',
                'quantity' => 10,
                'occurred_at' => $today,
                'billing_period_key' => $today->format('Y-m'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'team_id' => $team->id,
                'usage_meter_id' => $meter->id,
                'event_key' => 'evt-2',
                'quantity' => 25,
                'occurred_at' => $today,
                'billing_period_key' => $today->format('Y-m'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'team_id' => $team->id,
                'usage_meter_id' => $meter->id,
                'event_key' => 'evt-3',
                'quantity' => 15,
                'occurred_at' => $today,
                'billing_period_key' => $today->format('Y-m'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $job = new AggregateUsageJob($team->id);
        $job->handle();

        $aggregate = UsageDailyAggregate::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', $meter->id)
            ->where('day', $today->toDateString())
            ->first();

        $this->assertNotNull($aggregate, 'Daily aggregate should be created for the team and meter');
        $this->assertEquals(50, $aggregate->quantity_sum, 'Daily aggregate should sum all events: 10+25+15=50');
        $this->assertEquals(25, $aggregate->quantity_max, 'Daily aggregate should track max quantity: max(10,25,15)=25');
    }

    public function test_usage_counter_calculates_overage(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'ai_calls']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 100,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'overage-evt-1',
            'quantity' => 150,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new AggregateUsageJob($team->id);
        $job->handle();

        $this->assertDatabaseHas('tenant_usage_counters', [
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'consumed_value' => 150,
            'included_value' => 100,
            'overage_value' => 50,
        ]);

        $computed = $this->assertSystemLogged('billing.overage.computed', fn (array $c) => $c['input']['meter_code'] === 'ai_calls');
        $this->assertSame($team->id, $computed['input']['team_id']);
        $this->assertSame(now()->startOfMonth()->toDateString(), $computed['input']['period_start']);
        $this->assertSame('sum', $computed['calc']['aggregation_type']);
        $this->assertSame(150, $computed['calc']['consumed']);
        $this->assertSame(100, $computed['calc']['included']);
        $this->assertSame('plan_rate', $computed['calc']['included_source']);
        $this->assertSame(max(0, $computed['calc']['consumed'] - $computed['calc']['included']), $computed['result']['overage']);
        $this->assertSame(
            TenantUsageCounter::withoutGlobalScopes()->where('team_id', $team->id)->where('usage_meter_id', $meter->id)->sole()->overage_value,
            $computed['result']['overage'],
        );

        $completed = $this->assertSystemLogged('billing.aggregate.completed', fn (array $c) => $c['input']['team_id'] === $team->id);
        $this->assertSame('ok', $completed['outcome']);
        $this->assertFalse($completed['calc']['closed_period']);
        $this->assertSame(UsageMeter::count(), $completed['result']['meters_count']);
        $this->assertSame(1, $completed['result']['meters_with_overage_count']);
        $this->assertSame(1, $completed['result']['daily_rows_upserted_count']);
        $this->assertSame(1, $completed['result']['limit_events_dispatched_count']);
        $this->assertSame(0, $completed['result']['broadcasts_dispatched_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_usage_limit_exceeded_event_dispatched_on_overage(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'ai_tokens_in']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 1000,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'limit-evt-1',
            'quantity' => 1500,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new AggregateUsageJob($team->id);
        $job->handle();

        Event::assertDispatched(UsageLimitExceeded::class, function (UsageLimitExceeded $event) use ($team) {
            return $event->teamId === $team->id
                && $event->meterCode === 'ai_tokens_in'
                && $event->consumed === 1500
                && $event->included === 1000;
        });

        $first = $this->assertSystemLogged('billing.overage.computed', fn (array $c) => $c['input']['meter_code'] === 'ai_tokens_in');
        $this->assertTrue($first['calc']['first_crossing']);
        $this->assertSame(0, $first['calc']['previous_overage']);
        $this->assertFalse($first['calc']['previous_counter_found']);
        $this->assertFalse($first['calc']['closed_period']);
        $this->assertTrue($first['result']['limit_event_dispatched']);
        $this->assertSame(500, $first['result']['overage']);
        $firstEntry = collect($this->systemLogEntries('billing.overage.computed'))->first(fn (array $e) => $e['context']['input']['meter_code'] === 'ai_tokens_in');
        $this->assertSame('info', $firstEntry['level']);

        // Segunda corrida: ya estaba por encima, no es el primer cruce.
        $this->setUpAssertsSystemLog();
        (new AggregateUsageJob($team->id))->handle();

        $second = $this->assertSystemLogged('billing.overage.computed', fn (array $c) => $c['input']['meter_code'] === 'ai_tokens_in');
        $this->assertFalse($second['calc']['first_crossing']);
        $this->assertTrue($second['calc']['previous_counter_found']);
        $this->assertGreaterThan(0, $second['calc']['previous_overage']);
        $this->assertFalse($second['result']['limit_event_dispatched']);
        Event::assertDispatchedTimes(UsageLimitExceeded::class, 1);

        // Los medidores sin consumo ni cruce van en debug.
        foreach ($this->systemLogEntries('billing.overage.computed') as $entry) {
            if ($entry['context']['result']['overage'] === 0 && ! $entry['context']['calc']['first_crossing']) {
                $this->assertSame('debug', $entry['level']);
            }
        }
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_tenant_without_an_operational_subscription_is_skipped_with_a_reason(): void
    {
        $team = Team::factory()->create();
        Subscription::factory()->create([
            'team_id' => $team->id,
            'status' => SubscriptionStatus::Canceled,
        ]);

        (new AggregateUsageJob($team->id))->handle();

        $skipped = $this->assertSystemLogged('billing.aggregate.completed');
        $this->assertSame('skipped', $skipped['outcome']);
        $this->assertSame('no_operational_subscription', $skipped['reason']);
        $this->assertSame($team->id, $skipped['input']['team_id']);
        $this->assertSame(now()->startOfMonth()->toDateString(), $skipped['input']['period_start']);
        $this->assertSystemNotLogged('billing.overage.computed');
        $this->assertSame(0, TenantUsageCounter::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_scheduled_run_fans_out_one_job_per_subscribed_tenant(): void
    {
        Queue::fake();

        $plan = Plan::factory()->create();
        $subscribed = Team::factory()->count(2)->create()->each(fn (Team $team) => Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]));
        Team::factory()->create(); // no subscription: nothing to aggregate

        (new AggregateUsageJob(forMonth: '2026-08-15'))->handle();

        Queue::assertPushed(AggregateUsageJob::class, 2);

        foreach ($subscribed as $team) {
            Queue::assertPushed(AggregateUsageJob::class, fn (AggregateUsageJob $job) => $job->teamId === $team->id
                && $job->forMonth === '2026-08-15'
                && $job->queue === 'billing');
        }

        $fanned = $this->assertSystemLogged('billing.aggregate.fanned_out');
        $this->assertSame(2, $fanned['result']['jobs_dispatched_count']);
        $this->assertSame('2026-08-15', $fanned['calc']['for_month']);
        $this->assertSame('2026-08-01', $fanned['calc']['period_start']);
        $this->assertArrayNotHasKey('input', $fanned);
        $json = json_encode($fanned);
        $this->assertStringNotContainsString('team_id', $json);
        $this->assertNoSensitiveDataLogged();
    }
}
