<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BillingRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_rates_determine_included_quantity(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'monitored_assets']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 500,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'asset-snapshot-1',
            'quantity' => 300,
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
            'consumed_value' => 300,
            'included_value' => 500,
            'overage_value' => 0,
        ]);
    }

    public function test_billing_rate_with_zero_included_means_all_metered(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'ai_tokens_out']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->metered()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'tokens-1',
            'quantity' => 500,
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
            'consumed_value' => 500,
            'included_value' => 0,
            'overage_value' => 500,
        ]);
    }
}
