<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * La consola /admin y la entrada a clientes ajenos exigen 2FA confirmado al
 * super-admin (RequireSuperAdminTwoFactor, `auth.super_admin.require_two_factor`).
 */
class AdminTwoFactorRequiredTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private function superAdminWithoutTwoFactor(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_a_super_admin_without_two_factor_is_sent_to_set_it_up(): void
    {
        $operator = $this->superAdminWithoutTwoFactor();

        $this->actingAs($operator)
            ->get(route('admin.tenants.index'))
            ->assertRedirect(route('security.edit'))
            ->assertInertiaFlash('toast.type', 'warning')
            ->assertInertiaFlash('toast.message', 'Para usar la consola de SAM necesitas activar la verificación en dos pasos.');

        $this->assertSystemLogged('tenancy.admin_access.denied', fn (array $c): bool => $c['reason'] === 'two_factor_required'
            && $c['input']['user_id'] === $operator->id
            && $c['input']['surface'] === 'admin_console'
            && $c['input']['route_name'] === 'admin.tenants.index'
            && $c['input']['pending_confirmation'] === false
            && $c['input']['expects_json'] === false);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_json_requests_get_a_403_with_the_reason(): void
    {
        $this->actingAs($this->superAdminWithoutTwoFactor())
            ->getJson(route('admin.tenants.index'))
            ->assertForbidden()
            ->assertExactJson([
                'message' => RequireSuperAdminTwoFactor::MESSAGE,
                'reason' => 'two_factor_required',
            ]);

        $this->assertSystemLogged('tenancy.admin_access.denied', fn (array $c): bool => $c['input']['expects_json'] === true);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_blocked_mutation_does_not_run(): void
    {
        $team = Team::factory()->create(['is_personal' => false]);
        Subscription::factory()->create(['team_id' => $team->id, 'status' => SubscriptionStatus::Active]);

        $this->actingAs($this->superAdminWithoutTwoFactor())
            ->post(route('admin.tenants.subscription.suspend', $team))
            ->assertRedirect(route('security.edit'));

        $this->assertSame(
            SubscriptionStatus::Active,
            Subscription::withoutGlobalScopes()->where('team_id', $team->id)->sole()->status,
        );
    }

    public function test_two_factor_enabled_but_not_confirmed_counts_as_missing(): void
    {
        $operator = User::factory()->create([
            'global_role' => 'super_admin',
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($operator)
            ->get(route('admin.tenants.index'))
            ->assertRedirect(route('security.edit'));

        $this->assertSystemLogged('tenancy.admin_access.denied', fn (array $c): bool => $c['input']['pending_confirmation'] === true);
    }

    public function test_a_super_admin_with_confirmed_two_factor_gets_in(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.tenants.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/tenants/index'));

        $this->assertSystemNotLogged('tenancy.admin_access.denied');
    }

    public function test_the_requirement_can_be_turned_off_by_config(): void
    {
        config(['auth.super_admin.require_two_factor' => false]);

        $this->actingAs($this->superAdminWithoutTwoFactor())
            ->get(route('admin.tenants.index'))
            ->assertOk();

        $this->assertSystemNotLogged('tenancy.admin_access.denied');
    }

    public function test_non_super_admins_are_still_forbidden_and_never_redirected(): void
    {
        $member = User::factory()->withTwoFactor()->create();
        $plain = User::factory()->create();

        $this->actingAs($member)->get(route('admin.tenants.index'))->assertForbidden();
        $this->actingAs($plain)->get(route('admin.tenants.index'))->assertForbidden();
        $this->actingAs($plain)->getJson(route('admin.tenants.index'))->assertForbidden();

        $this->assertSystemNotLogged('tenancy.admin_access.denied');
    }

    public function test_security_profile_and_logout_stay_reachable_without_a_loop(): void
    {
        $operator = $this->superAdminWithoutTwoFactor();

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/security')
                ->where('twoFactorEnabled', false));

        $this->actingAs($operator)->get(route('profile.edit'))->assertOk();

        $this->assertSystemNotLogged('tenancy.admin_access.denied');

        $this->actingAs($operator)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_entering_a_foreign_tenant_also_requires_two_factor(): void
    {
        $operator = $this->superAdminWithoutTwoFactor();
        $ownTeamId = $operator->current_team_id;
        $customer = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($operator)
            ->get(route('dashboard', $customer))
            ->assertRedirect(route('security.edit'));

        $this->assertSame($ownTeamId, $operator->fresh()->current_team_id);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'impersonation.started']);
        $this->assertSystemLogged('tenancy.admin_access.denied', fn (array $c): bool => $c['input']['surface'] === 'tenant_entry'
            && $c['input']['user_id'] === $operator->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_super_admin_without_two_factor_keeps_access_to_its_own_teams(): void
    {
        $operator = $this->superAdminWithoutTwoFactor();
        $ownTeam = $operator->personalTeam();
        $this->assertNotNull($ownTeam);

        $this->actingAs($operator)
            ->get(route('dashboard', $ownTeam))
            ->assertOk();

        $this->assertSystemNotLogged('tenancy.admin_access.denied');
    }

    public function test_a_super_admin_with_two_factor_enters_a_foreign_tenant(): void
    {
        $operator = User::factory()->superAdmin()->create();
        $customer = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($operator)
            ->get(route('dashboard', $customer))
            ->assertOk();

        $this->assertSame($customer->id, $operator->fresh()->current_team_id);
    }
}
