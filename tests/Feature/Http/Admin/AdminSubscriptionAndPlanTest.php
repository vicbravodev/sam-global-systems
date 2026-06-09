<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AssetMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSubscriptionAndPlanTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    private function tenantWithSubscription(SubscriptionStatus $status = SubscriptionStatus::Active): Team
    {
        $team = Team::factory()->create(['is_personal' => false]);
        Subscription::factory()->create(['team_id' => $team->id, 'status' => $status]);

        return $team;
    }

    public function test_non_super_admin_cannot_change_subscription(): void
    {
        $user = User::factory()->create();
        $team = $this->tenantWithSubscription();
        $plan = Plan::factory()->create();

        $this->actingAs($user)
            ->put(route('admin.tenants.subscription.update', $team), ['plan_code' => $plan->code])
            ->assertForbidden();
    }

    public function test_super_admin_changes_plan_and_audits(): void
    {
        $admin = $this->superAdmin();
        $team = $this->tenantWithSubscription();
        $plan = Plan::factory()->create(['code' => 'growth']);

        $this->actingAs($admin)
            ->put(route('admin.tenants.subscription.update', $team), ['plan_code' => 'growth'])
            ->assertRedirect(route('admin.tenants.show', $team));

        $this->assertDatabaseHas('team_subscriptions', [
            'team_id' => $team->id,
            'plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'tenant.plan_changed',
        ]);
    }

    public function test_super_admin_suspends_and_reactivates(): void
    {
        $admin = $this->superAdmin();
        $team = $this->tenantWithSubscription();

        $this->actingAs($admin)
            ->post(route('admin.tenants.subscription.suspend', $team))
            ->assertRedirect();

        $this->assertSame(
            SubscriptionStatus::Suspended,
            Subscription::withoutGlobalScopes()->where('team_id', $team->id)->first()->status,
        );

        $this->actingAs($admin)
            ->post(route('admin.tenants.subscription.reactivate', $team))
            ->assertRedirect();

        $this->assertTrue(
            Subscription::withoutGlobalScopes()->where('team_id', $team->id)->first()
                ->status->grantsOperationalAccess(),
        );
    }

    public function test_super_admin_extends_trial(): void
    {
        $admin = $this->superAdmin();
        $team = $this->tenantWithSubscription(SubscriptionStatus::Trialing);

        $this->actingAs($admin)
            ->post(route('admin.tenants.subscription.extend-trial', $team), ['days' => 30])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'tenant.trial_extended',
        ]);
    }

    public function test_plans_index_renders_for_super_admin(): void
    {
        $admin = $this->superAdmin();
        Plan::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.plans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/plans/index')->has('plans'));
    }

    public function test_super_admin_updates_plan_limits_and_audits(): void
    {
        $admin = $this->superAdmin();
        $this->seed(AssetMeterSeeder::class);
        $plan = Plan::factory()->create();
        $meterId = UsageMeter::where('code', 'monitored_assets')->value('id');

        $this->actingAs($admin)
            ->put(route('admin.plans.update', $plan), ['limits' => ['monitored_assets' => 321]])
            ->assertRedirect(route('admin.plans.index'));

        $this->assertSame(
            321,
            (int) BillingRate::where('plan_id', $plan->id)
                ->where('usage_meter_id', $meterId)
                ->value('included_quantity'),
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.limits_updated']);
    }

    public function test_plans_routes_blocked_for_regular_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.plans.index'))->assertForbidden();
    }
}
