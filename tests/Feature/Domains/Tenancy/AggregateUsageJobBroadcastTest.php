<?php

namespace Tests\Feature\Domains\Tenancy;

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
use Tests\TestCase;

class AggregateUsageJobBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_all_subscribed_teams_when_team_id_is_null(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'bulk_meter']);

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $unsubscribedTeam = Team::factory()->create();

        foreach ([$teamA, $teamB] as $team) {
            Subscription::factory()->create([
                'team_id' => $team->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
            ]);

            UsageEvent::withoutGlobalScopes()->insert([
                'team_id' => $team->id,
                'usage_meter_id' => $meter->id,
                'event_key' => "bulk-{$team->id}",
                'quantity' => 1,
                'occurred_at' => now(),
                'billing_period_key' => now()->format('Y-m'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        (new AggregateUsageJob)->handle();

        $this->assertEquals(
            2,
            TenantUsageCounter::withoutGlobalScopes()->count(),
            'Job should aggregate counters for every team with an active/trialing/past_due subscription',
        );

        $this->assertDatabaseMissing('tenant_usage_counters', [
            'team_id' => $unsubscribedTeam->id,
        ]);
    }

    public function test_it_broadcasts_when_consumed_value_changes_more_than_five_percent(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'broadcast_meter']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 100_000,
        ]);

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'consumed_value' => 100,
            'included_value' => 100_000,
            'overage_value' => 0,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'broadcast-evt-1',
            'quantity' => 200, // 200 vs previous 100 → +100% change
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new AggregateUsageJob($team->id))->handle();

        Event::assertDispatched(UsageUpdatedBroadcast::class, function (UsageUpdatedBroadcast $event) use ($team) {
            return $event->teamId === $team->id
                && $event->meterCode === 'broadcast_meter'
                && $event->consumed === 200;
        });
    }

    public function test_it_does_not_broadcast_on_first_aggregation_when_no_previous_counter(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'first_run']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trialing,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'first-evt',
            'quantity' => 5,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new AggregateUsageJob($team->id))->handle();

        Event::assertNotDispatched(UsageUpdatedBroadcast::class);
    }

    public function test_it_treats_meter_without_subscription_or_rate_as_zero_included(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        $team = Team::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'no_rate_meter']);

        // Subscription exists but no BillingRate → included stays at 0
        Subscription::factory()->create([
            'team_id' => $team->id,
            'status' => SubscriptionStatus::PastDue,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'no-rate-evt',
            'quantity' => 10,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new AggregateUsageJob($team->id))->handle();

        $this->assertDatabaseHas('tenant_usage_counters', [
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'consumed_value' => 10,
            'included_value' => 0,
            'overage_value' => 10,
        ]);
    }
}
