<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\ChangeTenantPlan;
use App\Domains\Tenancy\Actions\ExtendTrial;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\UpdatePlanLimits;
use App\Domains\Tenancy\Actions\UpdateSubscriptionStatus;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Database\Seeders\AssetMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionControlActionsTest extends TestCase
{
    use RefreshDatabase;

    private function planWithAssetCap(int $cap): Plan
    {
        $this->seed(AssetMeterSeeder::class);
        $meterId = UsageMeter::where('code', 'monitored_assets')->value('id');

        $plan = Plan::factory()->create();
        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meterId,
            'included_quantity' => $cap,
        ]);

        return $plan;
    }

    public function test_change_tenant_plan_assigns_plan_and_seeds_features(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $plan = $this->planWithAssetCap(50);

        app(ChangeTenantPlan::class)->execute($team, $plan->code);

        $this->assertDatabaseHas('team_subscriptions', [
            'team_id' => $team->id,
            'plan_id' => $plan->id,
        ]);

        $feature = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('feature_key', 'monitored_assets')
            ->first();

        $this->assertNotNull($feature);
        $this->assertSame(50, (int) $feature->limits_json['included_quantity']);
    }

    public function test_change_tenant_plan_preserves_manual_overrides(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $plan = $this->planWithAssetCap(50);

        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'feature_key' => 'monitored_assets',
            'enabled' => true,
            'source' => FeatureSource::ManualOverride,
            'limits_json' => ['included_quantity' => 5],
        ]);

        app(ChangeTenantPlan::class)->execute($team, $plan->code);

        $feature = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('feature_key', 'monitored_assets')
            ->first();

        $this->assertSame(5, (int) $feature->limits_json['included_quantity'],
            'Manual override must not be clobbered by a plan change.');
    }

    public function test_update_subscription_status_suspends_and_reactivates(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
        ]);

        app(UpdateSubscriptionStatus::class)->execute($subscription, SubscriptionStatus::Suspended);
        $this->assertFalse($subscription->fresh()->status->grantsOperationalAccess());

        app(UpdateSubscriptionStatus::class)->execute($subscription, SubscriptionStatus::Active);
        $fresh = $subscription->fresh();
        $this->assertTrue($fresh->status->grantsOperationalAccess());
        $this->assertNull($fresh->ends_at);
    }

    public function test_cancel_sets_end_date(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Active,
        ]);

        app(UpdateSubscriptionStatus::class)->execute($subscription, SubscriptionStatus::Canceled);

        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Canceled, $fresh->status);
        $this->assertNotNull($fresh->ends_at);
        $this->assertTrue((bool) $fresh->cancel_at_period_end);
    }

    public function test_extend_trial_pushes_trial_end_forward(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays(2),
        ]);

        $expected = $subscription->trial_ends_at->copy()->addDays(10)->toDateString();

        app(ExtendTrial::class)->execute($subscription, 10);

        // Extends from the existing trial end (now+2 → now+12), not from "now".
        $this->assertSame(
            $expected,
            $subscription->fresh()->trial_ends_at->toDateString(),
        );
    }

    public function test_resolve_asset_limit_prefers_tenant_feature_then_plan(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $plan = $this->planWithAssetCap(40);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
        ]);

        // No feature override → falls back to the plan billing rate.
        $this->assertSame(40, app(ResolveAssetLimit::class)->execute((int) $team->id));

        // Feature override wins.
        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'feature_key' => 'monitored_assets',
            'enabled' => true,
            'source' => FeatureSource::ManualOverride,
            'limits_json' => ['included_quantity' => 7],
        ]);

        $this->assertSame(7, app(ResolveAssetLimit::class)->execute((int) $team->id));
    }

    public function test_resolve_asset_limit_is_null_without_plan_or_feature(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $this->seed(AssetMeterSeeder::class);

        $this->assertNull(app(ResolveAssetLimit::class)->execute((int) $team->id));
    }

    public function test_update_plan_limits_changes_included_quantity(): void
    {
        $plan = $this->planWithAssetCap(10);
        $meterId = UsageMeter::where('code', 'monitored_assets')->value('id');

        app(UpdatePlanLimits::class)->execute($plan, ['monitored_assets' => 123]);

        $this->assertSame(
            123,
            (int) BillingRate::where('plan_id', $plan->id)
                ->where('usage_meter_id', $meterId)
                ->value('included_quantity'),
        );
    }
}
