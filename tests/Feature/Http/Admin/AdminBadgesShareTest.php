<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBadgesShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_receives_cross_tenant_badge_counts(): void
    {
        $admin = User::factory()->create(['global_role' => 'super_admin']);

        Subscription::factory()->count(2)->pastDue()->create();
        Subscription::factory()->trialing()->create();
        Subscription::factory()->create(); // active → not counted

        $this->actingAs($admin)
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('adminBadges.tenantsPastDue', 2)
                    ->where('adminBadges.tenantsTrialing', 1),
            );
    }

    public function test_regular_member_does_not_receive_admin_badges(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => 'member']);
        $user->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($user)
            ->get(route('dashboard', $team))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page->where('adminBadges', null),
            );
    }
}
