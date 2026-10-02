<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Actions\GuardRoleDelegation;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Fase 6 del log narrativo: cada decisión de acceso (negaciones, escalada
 * bloqueada, cambios de rol y de membresía, invitaciones, impersonación y
 * operadores) deja su código, sólo con ids y sin emails ni nombres.
 */
class AccessSystemLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->owner = User::factory()->create(['email' => 'duena@empresa.mx', 'name' => 'Ana Dueña']);
        $this->team = Team::factory()->create(['is_personal' => false]);
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $this->owner->switchTeam($this->team);
    }

    private function member(string $legacyRole = 'member', ?string $roleCode = null): User
    {
        $user = User::factory()->create(['email' => 'miembro'.uniqid().'@empresa.mx']);
        $this->team->members()->attach($user, [
            'role' => $legacyRole,
            'role_id' => $roleCode !== null ? Role::where('code', $roleCode)->value('id') : null,
        ]);
        $user->switchTeam($this->team);

        return $user;
    }

    private function membership(User $user): Membership
    {
        return Membership::where('team_id', $this->team->id)->where('user_id', $user->id)->firstOrFail();
    }

    private function assertNoEmailsLogged(string ...$emails): void
    {
        $json = (string) json_encode($this->systemLogEntries());

        foreach ($emails as $email) {
            $this->assertStringNotContainsString($email, $json);
        }

        $this->assertNoSensitiveDataLogged();
    }

    public function test_authorize_action_denials_are_logged_at_debug_with_their_reason(): void
    {
        $viewer = $this->member('member', 'viewer');

        $this->assertFalse(app(AuthorizeAction::class)->execute($viewer, 'incidents.manage', $this->team));

        $ctx = $this->assertSystemLogged('access.check.denied', fn (array $c) => $c['reason'] === 'permission');
        $this->assertSame(['user_id' => $viewer->id, 'team_id' => $this->team->id, 'permission' => 'incidents.manage'], $ctx['input']);
        $this->assertSame('debug', $this->systemLogEntries('access.check.denied')[0]['level']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_disabled_feature_is_logged_as_the_denial_reason(): void
    {
        $this->team->features()->create(['feature_key' => 'incidents', 'enabled' => false, 'source' => FeatureSource::ManualOverride]);

        $this->assertFalse(app(AuthorizeAction::class)->execute($this->owner, 'incidents.view', $this->team));

        $ctx = $this->assertSystemLogged('access.check.denied', fn (array $c) => $c['reason'] === 'feature');
        $this->assertSame('incidents', $ctx['calc']['feature_key']);
    }

    public function test_a_non_member_opening_a_tenant_route_is_logged(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('dashboard', ['current_team' => $this->team->slug]))->assertForbidden();

        $ctx = $this->assertSystemLogged('access.check.denied', fn (array $c) => $c['reason'] === 'not_member');
        $this->assertSame($outsider->id, $ctx['input']['user_id']);
        $this->assertSame($this->team->id, $ctx['input']['team_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_switching_team_is_logged_and_a_foreign_switch_is_denied(): void
    {
        $other = Team::factory()->create(['is_personal' => false]);
        $other->members()->attach($this->owner, ['role' => TeamRole::Member->value]);

        $this->actingAs($this->owner)->post(route('teams.switch', $other))->assertRedirect();

        $ctx = $this->assertSystemLogged('access.team.switched');
        $this->assertSame($other->id, $ctx['input']['team_id']);
        $this->assertSame($this->team->id, $ctx['result']['previous_team_id']);

        $foreign = Team::factory()->create(['is_personal' => false]);
        $this->actingAs($this->owner)->post(route('teams.switch', $foreign))->assertForbidden();

        $this->assertSystemLogged('access.check.denied', fn (array $c) => $c['reason'] === 'not_member' && $c['input']['team_id'] === $foreign->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_admin_console_denial_is_logged(): void
    {
        $this->actingAs($this->owner)->get(route('admin.tenants.index'))->assertForbidden();

        $ctx = $this->assertSystemLogged('access.super_admin.denied', fn (array $c) => $c['reason'] === 'not_super_admin');
        $this->assertSame($this->owner->id, $ctx['input']['user_id']);
        $this->assertSame('admin.tenants.index', $ctx['input']['route_name']);
    }

    public function test_a_super_admin_entering_a_foreign_tenant_by_url_is_a_warning(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'ops@sam.mx']);

        $this->actingAs($admin)->get(route('dashboard', ['current_team' => $this->team->slug]))->assertOk();

        $ctx = $this->assertSystemLogged('access.super_admin.forced_team_switch', fn (array $c) => $c['reason'] === 'direct_url');
        $this->assertSame(['user_id' => $admin->id, 'team_id' => $this->team->id, 'route_name' => 'dashboard'], $ctx['input']);
        $this->assertSame('warning', $this->systemLogEntries('access.super_admin.forced_team_switch')[0]['level']);
        $this->assertNoEmailsLogged('ops@sam.mx');
    }

    public function test_impersonation_start_and_stop_are_logged(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $personal = $admin->currentTeam;

        $this->actingAs($admin)->post(route('admin.impersonate.store', $this->team))->assertRedirect();
        $started = $this->assertSystemLogged('access.impersonation.started', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame($this->team->id, $started['input']['team_id']);
        $this->assertFalse($started['result']['is_member']);

        $this->actingAs($admin->fresh())->delete(route('admin.impersonate.destroy'))->assertRedirect();
        $stopped = $this->assertSystemLogged('access.impersonation.stopped', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame($this->team->id, $stopped['input']['team_id']);
        $this->assertSame($personal->id, $stopped['result']['returned_to_team_id']);

        $this->actingAs($admin)->post(route('admin.impersonate.store', $personal))->assertRedirect();
        $this->assertSystemLogged('access.impersonation.started', fn (array $c) => ($c['reason'] ?? null) === 'personal_team');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_blocked_escalation_attempts_are_logged_with_the_rule_that_stopped_them(): void
    {
        $coOwner = $this->member('owner');

        $this->actingAs($this->owner)
            ->patch(route('teams.members.update', [$this->team, $this->owner]), ['role' => 'member'])
            ->assertForbidden();
        $this->assertSystemLogged('access.role_delegation.denied', fn (array $c) => $c['reason'] === 'self_change' && $c['input']['check'] === 'change_membership');

        $this->actingAs($this->owner)
            ->patch(route('teams.members.update', [$this->team, $coOwner]), ['role' => 'member'])
            ->assertForbidden();
        $this->assertSystemLogged('access.role_delegation.denied', fn (array $c) => $c['reason'] === 'owner_protected' && $c['input']['target_user_id'] === $coOwner->id);

        $supervisor = $this->member('admin', 'supervisor');
        $tenantAdmin = $this->member('admin', 'tenant_admin');
        $guard = app(GuardRoleDelegation::class);

        try {
            $guard->assertCanChangeMembership($supervisor, $this->membership($tenantAdmin));
            $this->fail('Debió negarse.');
        } catch (AuthorizationException) {
        }

        $outranks = $this->assertSystemLogged('access.role_delegation.denied', fn (array $c) => $c['reason'] === 'target_outranks_actor');
        $this->assertSame($tenantAdmin->id, $outranks['input']['target_user_id']);
        $this->assertGreaterThan(0, count($outranks['calc']['missing_permissions']));

        try {
            $guard->assertCanGrantRole($supervisor, $this->team, Role::where('code', 'tenant_admin')->firstOrFail());
            $this->fail('Debió negarse.');
        } catch (AuthorizationException) {
        }

        $ctx = $this->assertSystemLogged('access.role_delegation.denied', fn (array $c) => $c['reason'] === 'permissions_not_held');
        $this->assertSame('grant_permissions', $ctx['input']['check']);
        $this->assertSame($supervisor->id, $ctx['input']['actor_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_granting_a_team_role_above_your_own_is_logged(): void
    {
        $admin = $this->member('admin');

        try {
            app(GuardRoleDelegation::class)->assertCanGrantTeamRole($admin, $this->team, TeamRole::Owner);
            $this->fail('Debió negarse.');
        } catch (AuthorizationException) {
        }

        $ctx = $this->assertSystemLogged('access.role_delegation.denied', fn (array $c) => $c['reason'] === 'role_above_own');
        $this->assertSame('owner', $ctx['input']['requested_role']);
        $this->assertSame('admin', $ctx['input']['actor_role']);
        $this->assertSame('grant_team_role', $ctx['input']['check']);
    }

    public function test_team_role_changes_are_logged_after_commit_with_previous_and_new_role(): void
    {
        $target = $this->member('member', 'viewer');

        $this->actingAs($this->owner)
            ->patch(route('teams.members.update', [$this->team, $target]), ['role' => 'admin'])
            ->assertRedirect();

        $ctx = $this->assertSystemLogged('access.member.role_changed');
        $this->assertSame(['team_id' => $this->team->id, 'user_id' => $target->id, 'actor_id' => $this->owner->id], $ctx['input']);
        $this->assertSame(['previous_role' => 'member', 'role' => 'admin', 'rbac_role_cleared' => true], $ctx['result']);
        $this->assertNoEmailsLogged($target->email, 'duena@empresa.mx');
    }

    public function test_rbac_role_assignment_logs_system_codes_but_not_custom_ones(): void
    {
        $target = $this->member();

        $this->actingAs($this->owner)
            ->put(route('access.members.role.update', ['current_team' => $this->team->slug, 'membership' => $this->membership($target)]), ['role_code' => 'supervisor'])
            ->assertRedirect();

        $ctx = $this->assertSystemLogged('access.member.role_assigned');
        $this->assertSame('supervisor', $ctx['result']['role_code']);
        $this->assertTrue($ctx['result']['is_system_role']);

        $custom = Role::create([
            'team_id' => $this->team->id,
            'name' => 'Turno de Ana',
            'code' => Role::customCodeFor($this->team->id, 'turno_ana'),
            'scope' => 'tenant',
            'is_system' => false,
        ]);

        $this->actingAs($this->owner)
            ->put(route('access.members.role.update', ['current_team' => $this->team->slug, 'membership' => $this->membership($target)]), ['role_code' => $custom->code])
            ->assertRedirect();

        $ctx = $this->assertSystemLogged('access.member.role_assigned', fn (array $c) => $c['result']['role_id'] === $custom->id);
        $this->assertNull($ctx['result']['role_code']);
        $this->assertStringNotContainsString('turno_ana', (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_role_permission_sync_and_deletion_are_logged(): void
    {
        $this->actingAs($this->owner)->post(route('access.roles.store', ['current_team' => $this->team->slug]), [
            'name' => 'Turno noche',
            'code' => 'turno_noche',
            'permissions' => ['incidents.view', 'incidents.manage'],
        ])->assertRedirect();

        $role = Role::where('code', Role::customCodeFor($this->team->id, 'turno_noche'))->firstOrFail();

        $ctx = $this->assertSystemLogged('access.role.permissions_synced');
        $this->assertSame($role->id, $ctx['input']['role_id']);
        $this->assertSame(['requested_count' => 2, 'known_count' => 2], $ctx['calc']);
        $this->assertSame(2, $ctx['result']['attached_count']);

        $this->actingAs($this->owner)->delete(route('access.roles.destroy', ['current_team' => $this->team->slug, 'role' => $role->id]))->assertRedirect();

        $this->assertSystemLogged('access.role.deleted', fn (array $c) => $c['input']['role_id'] === $role->id);
        $this->assertStringNotContainsString('Turno noche', (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_removing_a_member_from_the_tenant_is_logged(): void
    {
        $target = $this->member();

        $this->actingAs($this->owner)->delete(route('teams.members.destroy', [$this->team, $target]))->assertRedirect();

        $ctx = $this->assertSystemLogged('access.member.removed');
        $this->assertSame(['team_id' => $this->team->id, 'user_id' => $target->id, 'actor_id' => $this->owner->id, 'via' => 'tenant_settings'], $ctx['input']);
        $this->assertNoEmailsLogged($target->email);
    }

    public function test_invitation_created_cancelled_and_accepted_are_logged_without_email_or_code(): void
    {
        Notification::fake();

        $this->actingAs($this->owner)->post(route('teams.invitations.store', $this->team), [
            'email' => 'nueva@empresa.mx',
            'role' => TeamRole::Member->value,
        ])->assertRedirect();

        $invitation = TeamInvitation::where('email', 'nueva@empresa.mx')->firstOrFail();
        $created = $this->assertSystemLogged('access.invitation.created');
        $this->assertSame($invitation->id, $created['result']['invitation_id']);
        $this->assertSame('member', $created['result']['role']);

        $this->actingAs($this->owner)->delete(route('teams.invitations.destroy', [$this->team, $invitation]))->assertRedirect();
        $this->assertSystemLogged('access.invitation.cancelled', fn (array $c) => $c['input']['invitation_id'] === $invitation->id);

        $fresh = TeamInvitation::factory()->create(['team_id' => $this->team->id, 'email' => 'otra@empresa.mx', 'role' => TeamRole::Member, 'invited_by' => $this->owner->id]);
        $invitee = User::factory()->create(['email' => 'otra@empresa.mx']);

        $this->actingAs($invitee)->post(route('invitations.accept', $fresh))->assertRedirect();

        $accepted = $this->assertSystemLogged('access.invitation.accepted');
        $this->assertSame(['team_id' => $this->team->id, 'invitation_id' => $fresh->id, 'user_id' => $invitee->id], $accepted['input']);
        $this->assertTrue($accepted['result']['membership_created']);

        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString($invitation->code, $json);
        $this->assertStringNotContainsString($fresh->code, $json);
        $this->assertNoEmailsLogged('nueva@empresa.mx', 'otra@empresa.mx');
    }

    public function test_rejected_invitations_are_logged_with_their_reason(): void
    {
        $invitation = TeamInvitation::factory()->create(['team_id' => $this->team->id, 'email' => 'invitada@empresa.mx', 'role' => TeamRole::Member, 'invited_by' => $this->owner->id]);
        $wrong = User::factory()->create(['email' => 'otro@empresa.mx']);

        $this->actingAs($wrong)->post(route('invitations.accept', $invitation))->assertSessionHasErrors('invitation');
        $this->assertSystemLogged('access.invitation.rejected', fn (array $c) => $c['reason'] === 'email_mismatch' && $c['input']['user_id'] === $wrong->id);

        $invitation->forceFill(['expires_at' => now()->subDay()])->save();
        $this->actingAs($wrong)->post(route('invitations.accept', $invitation))->assertSessionHasErrors('invitation');
        $this->assertSystemLogged('access.invitation.rejected', fn (array $c) => $c['reason'] === 'expired');

        auth()->logout();
        $this->post(route('invitations.register', $invitation), ['name' => 'X', 'password' => 'password-123-Ab!', 'password_confirmation' => 'password-123-Ab!'])
            ->assertSessionHasErrors('invitation');
        $this->assertSystemLogged('access.invitation.rejected', fn (array $c) => $c['reason'] === 'expired' && $c['input']['stage'] === 'register');

        $this->assertNoEmailsLogged('invitada@empresa.mx', 'otro@empresa.mx');
    }

    public function test_console_member_add_and_remove_are_logged(): void
    {
        Notification::fake();
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post(route('admin.tenants.members.store', $this->team), [
            'email' => 'alta@empresa.mx',
            'name' => 'Beto Alta',
            'role' => TeamRole::Member->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $added = $this->assertSystemLogged('access.member.added');
        $this->assertSame('admin_console', $added['input']['via']);
        $this->assertTrue($added['result']['user_created']);
        $this->assertTrue($added['result']['access_link_queued']);

        $user = User::findByEmail('alta@empresa.mx');
        $this->assertNotNull($user);

        $this->actingAs($admin)->delete(route('admin.tenants.members.destroy', [$this->team, $user]))->assertRedirect();
        $this->assertSystemLogged('access.member.removed', fn (array $c) => $c['input']['via'] === 'admin_console' && $c['input']['user_id'] === $user->id);

        $this->assertStringNotContainsString('Beto Alta', (string) json_encode($this->systemLogEntries()));
        $this->assertNoEmailsLogged('alta@empresa.mx');
    }

    public function test_operator_grants_revocations_and_refusals_are_logged(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'ops@sam.mx']);
        $target = User::factory()->create(['email' => 'nuevo-op@sam.mx']);

        $this->actingAs($admin)->post(route('admin.operators.store'), ['email' => 'nuevo-op@sam.mx'])->assertRedirect();
        $this->assertSystemLogged('access.operator.granted', fn (array $c) => $c['input'] === ['actor_id' => $admin->id, 'user_id' => $target->id]);

        $this->actingAs($admin)->post(route('admin.operators.store'), ['email' => 'nuevo-op@sam.mx'])->assertSessionHasErrors('email');
        $this->assertSystemLogged('access.operator.rejected', fn (array $c) => $c['reason'] === 'already_super_admin');

        $this->actingAs($admin)->post(route('admin.operators.store'), ['email' => 'nadie@sam.mx'])->assertSessionHasErrors('email');
        $this->assertSystemLogged('access.operator.rejected', fn (array $c) => $c['reason'] === 'account_not_found' && $c['input']['user_id'] === null);

        $this->actingAs($admin)->delete(route('admin.operators.destroy', $admin))->assertSessionHasErrors('operator');
        $this->assertSystemLogged('access.operator.rejected', fn (array $c) => $c['reason'] === 'self_revocation');

        $this->actingAs($admin)->delete(route('admin.operators.destroy', $target))->assertRedirect();
        $this->assertSystemLogged('access.operator.revoked', fn (array $c) => $c['input']['user_id'] === $target->id);

        $this->assertNoEmailsLogged('ops@sam.mx', 'nuevo-op@sam.mx', 'nadie@sam.mx');
    }
}
