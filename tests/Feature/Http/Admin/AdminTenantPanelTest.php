<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTenantPanelTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_non_super_admin_cannot_reach_the_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.tenants.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.tenants.index'))->assertRedirect();
    }

    public function test_index_lists_tenants_across_all_teams(): void
    {
        $admin = $this->superAdmin();
        Team::factory()->create(['name' => 'Acme', 'is_personal' => false]);
        Team::factory()->create(['name' => 'Globex', 'is_personal' => false]);

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/tenants/index')
                    // 2 org tenants + the super-admin's own personal team.
                    ->has('tenants', 3)
                    ->where('stats.total', 2),
            );
    }

    public function test_store_creates_tenant_for_existing_owner(): void
    {
        $admin = $this->superAdmin();
        $owner = User::factory()->create();
        Plan::factory()->create(['code' => 'pro', 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), [
                'name' => 'Initech',
                'plan_code' => 'pro',
                'owner_email' => $owner->email,
            ])
            ->assertRedirect();

        $team = Team::where('name', 'Initech')->firstOrFail();
        $this->assertFalse($team->is_personal);
        $this->assertTrue(
            $team->members()->where('users.id', $owner->id)->exists(),
        );
        $this->assertDatabaseHas('team_subscriptions', [
            'team_id' => $team->id,
            'status' => SubscriptionStatus::Trialing->value,
        ]);
    }

    public function test_store_provisions_a_brand_new_owner(): void
    {
        Notification::fake();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), [
                'name' => 'NewCo',
                'owner_email' => 'founder@newco.test',
                'owner_name' => 'Founder Person',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'founder@newco.test']);
        $owner = User::where('email', 'founder@newco.test')->firstOrFail();
        // New owner keeps the personal-team invariant.
        $this->assertNotNull($owner->personalTeam());
        $this->assertTrue(
            Team::where('name', 'NewCo')->firstOrFail()
                ->members()->where('users.id', $owner->id)->exists(),
        );
    }

    public function test_store_requires_owner_name_for_unknown_email(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.tenants.store'), [
                'name' => 'NoNameCo',
                'owner_email' => 'ghost@nowhere.test',
            ])
            ->assertSessionHasErrors('owner_name');

        $this->assertDatabaseMissing('teams', ['name' => 'NoNameCo']);
    }

    public function test_show_renders_tenant_detail(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['name' => 'Umbrella', 'is_personal' => false]);
        $owner = User::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
        Subscription::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'plan_id' => Plan::factory()->create()->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.tenants.show', $team))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('admin/tenants/show')
                    ->where('tenant.name', 'Umbrella')
                    ->has('members', 1)
                    ->where('subscription.status', 'active'),
            );
    }
}
