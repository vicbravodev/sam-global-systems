<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\CreateTenant;
use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\TenantCreated;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreateTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_tenant_with_default_plan_and_features(): void
    {
        Event::fake([TenantCreated::class]);

        $owner = User::factory()->create();
        $plan = Plan::factory()->create(['code' => 'pro']);

        $meter = UsageMeter::factory()->create(['code' => 'api_requests']);
        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 5000,
        ]);

        $action = app(CreateTenant::class);
        $team = $action->execute('Acme Corp', $owner, 'pro');

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Acme Corp',
            'is_personal' => false,
        ]);

        $this->assertTrue(
            $team->members()->where('user_id', $owner->id)->where('role', TeamRole::Owner->value)->exists(),
            'Owner should be attached as team member with Owner role',
        );

        $this->assertDatabaseHas('team_subscriptions', [
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
        ]);

        $this->assertDatabaseHas('tenant_features', [
            'team_id' => $team->id,
            'feature_key' => 'api_requests',
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan->value,
        ]);

        $this->assertDatabaseHas('tenant_brandings', [
            'team_id' => $team->id,
        ]);
    }

    public function test_create_tenant_dispatches_tenant_created_event(): void
    {
        Event::fake([TenantCreated::class]);

        $owner = User::factory()->create();

        $action = app(CreateTenant::class);
        $team = $action->execute('Signal Corp', $owner);

        Event::assertDispatched(TenantCreated::class, function (TenantCreated $event) use ($team, $owner) {
            return $event->team->id === $team->id && $event->owner->id === $owner->id;
        });
    }

    public function test_tenant_with_plan_starts_active_without_trial(): void
    {
        Event::fake([TenantCreated::class]);

        $owner = User::factory()->create();
        Plan::factory()->create(['code' => 'starter', 'billing_cycle' => BillingCycle::Monthly]);

        $action = app(CreateTenant::class);
        $team = $action->execute('Direct Corp', $owner, 'starter');

        $subscription = $team->teamSubscription()->withoutGlobalScopes()->first();

        $this->assertNotNull($subscription, 'Subscription should exist for tenant created with a plan');
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->getAttribute('trial_ends_at'), 'SAM bills per asset-day from day one: no trial window');
    }

    public function test_it_creates_tenant_without_plan(): void
    {
        Event::fake([TenantCreated::class]);

        $owner = User::factory()->create();

        $action = app(CreateTenant::class);
        $team = $action->execute('Free Corp', $owner);

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Free Corp',
        ]);

        $this->assertDatabaseMissing('team_subscriptions', [
            'team_id' => $team->id,
        ]);

        $this->assertDatabaseHas('tenant_brandings', [
            'team_id' => $team->id,
        ]);
    }
}
