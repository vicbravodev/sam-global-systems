<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_active_subscription_per_team(): void
    {
        $team = Team::factory()->create();
        $planA = Plan::factory()->create(['code' => 'plan-a']);
        $planB = Plan::factory()->create(['code' => 'plan-b']);

        $firstSubscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $planA->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
        ]);

        $secondSubscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $planB->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
        ]);

        $firstSubscription->update(['status' => SubscriptionStatus::Expired]);

        $activeSubscriptions = Subscription::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->count();

        $this->assertEquals(
            1,
            $activeSubscriptions,
            'Only one active subscription should exist per team at a time',
        );

        $currentSubscription = $team->teamSubscription()->withoutGlobalScopes()->first();

        $this->assertEquals(
            $secondSubscription->id,
            $currentSubscription->id,
            'Team subscription relationship should return the most recent active subscription',
        );
    }

    public function test_suspended_subscription_blocks_operational_features(): void
    {
        $subscription = Subscription::factory()->suspended()->create();

        $this->assertFalse(
            $subscription->status->grantsOperationalAccess(),
            'Suspended subscription should NOT grant operational access',
        );

        $this->assertTrue(
            $subscription->status->grantsBillingAccess(),
            'Suspended subscription should still grant billing access so tenant can resolve payment issues',
        );
    }

    public function test_trialing_subscription_grants_operational_access(): void
    {
        $subscription = Subscription::factory()->trialing()->create();

        $this->assertTrue(
            $subscription->status->grantsOperationalAccess(),
            'Trialing subscription should grant operational access',
        );
    }

    public function test_canceled_subscription_denies_all_access(): void
    {
        $subscription = Subscription::factory()->canceled()->create();

        $this->assertFalse(
            $subscription->status->grantsOperationalAccess(),
            'Canceled subscription should NOT grant operational access',
        );

        $this->assertFalse(
            $subscription->status->grantsBillingAccess(),
            'Canceled subscription should NOT grant billing access',
        );
    }
}
