<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\UsageDailyAggregate;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AggregateUsageJobTest extends TestCase
{
    use RefreshDatabase;

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
    }
}
