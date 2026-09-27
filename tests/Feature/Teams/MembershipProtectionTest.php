<?php

namespace Tests\Feature\Teams;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nadie cambia ni quita a un propietario desde el tenant, nadie cambia su
 * propio rol y nadie concede más de lo que tiene. Y al degradar a alguien, sus
 * permisos RBAC (role_id + caché) bajan de verdad.
 */
class MembershipProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create();
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $this->owner->switchTeam($this->team);
    }

    private function member(string $legacyRole = 'member', ?string $roleCode = null): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, [
            'role' => $legacyRole,
            'role_id' => $roleCode ? Role::where('code', $roleCode)->value('id') : null,
        ]);
        $user->switchTeam($this->team);

        return $user;
    }

    private function membership(User $user): Membership
    {
        return Membership::where('team_id', $this->team->id)->where('user_id', $user->id)->firstOrFail();
    }

    public function test_an_owner_cannot_demote_another_owner(): void
    {
        $coOwner = $this->member('owner');

        $this->actingAs($this->owner)
            ->patch(route('teams.members.update', [$this->team, $coOwner]), ['role' => 'member'])
            ->assertForbidden();

        $this->assertSame(TeamRole::Owner, $coOwner->teamRole($this->team));
    }

    public function test_an_owner_cannot_remove_another_owner(): void
    {
        $coOwner = $this->member('owner');

        $this->actingAs($this->owner)
            ->delete(route('teams.members.destroy', [$this->team, $coOwner]))
            ->assertForbidden();

        $this->assertTrue($coOwner->fresh()->belongsToTeam($this->team));
    }

    public function test_nobody_can_change_their_own_team_role(): void
    {
        $this->actingAs($this->owner)
            ->patch(route('teams.members.update', [$this->team, $this->owner]), ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame(TeamRole::Owner, $this->owner->teamRole($this->team));
    }

    public function test_users_manage_holder_cannot_touch_the_owner_rbac_role(): void
    {
        $manager = $this->member('admin', 'tenant_admin');

        $this->actingAs($manager)
            ->put(route('access.members.role.update', [
                'current_team' => $this->team->slug,
                'membership' => $this->membership($this->owner)->id,
            ]), ['role_code' => 'viewer'])
            ->assertForbidden();

        $this->assertNull($this->membership($this->owner)->role_id);
    }

    public function test_users_manage_holder_cannot_change_their_own_rbac_role(): void
    {
        $manager = $this->member('member', 'tenant_admin');
        $supervisorId = Role::where('code', 'supervisor')->value('id');

        $this->actingAs($manager)
            ->put(route('access.members.role.update', [
                'current_team' => $this->team->slug,
                'membership' => $this->membership($manager)->id,
            ]), ['role_code' => 'supervisor'])
            ->assertForbidden();

        $this->assertNotSame($supervisorId, $this->membership($manager)->role_id);
    }

    public function test_cannot_grant_a_role_with_permissions_the_actor_lacks(): void
    {
        $limited = Role::factory()->create([
            'code' => 'people-ops',
            'is_system' => false,
            'team_id' => $this->team->id,
        ]);
        app(SyncRolePermissions::class)->execute($limited, ['users.view', 'users.manage', 'incidents.view']);

        $manager = $this->member('member', 'people-ops');
        $target = $this->member();

        $this->actingAs($manager)
            ->put(route('access.members.role.update', [
                'current_team' => $this->team->slug,
                'membership' => $this->membership($target)->id,
            ]), ['role_code' => 'tenant_admin'])
            ->assertForbidden();

        $this->assertNull($this->membership($target)->role_id);
    }

    public function test_cannot_modify_a_member_who_holds_more_than_the_actor(): void
    {
        $limited = Role::factory()->create([
            'code' => 'people-ops',
            'is_system' => false,
            'team_id' => $this->team->id,
        ]);
        app(SyncRolePermissions::class)->execute($limited, ['users.view', 'users.manage', 'incidents.view']);

        $manager = $this->member('member', 'people-ops');
        $admin = $this->member('admin', 'tenant_admin');

        $this->actingAs($manager)
            ->put(route('access.members.role.update', [
                'current_team' => $this->team->slug,
                'membership' => $this->membership($admin)->id,
            ]), ['role_code' => 'viewer'])
            ->assertForbidden();

        $this->assertSame(Role::where('code', 'tenant_admin')->value('id'), $this->membership($admin)->role_id);
    }

    public function test_demotion_through_team_settings_actually_drops_rbac_permissions(): void
    {
        $admin = $this->member('admin', 'tenant_admin');
        $authorize = app(AuthorizeAction::class);

        // Prime the permissions cache while still admin.
        $this->assertTrue($authorize->execute($admin, 'users.manage', $this->team));

        $this->actingAs($this->owner)
            ->patch(route('teams.members.update', [$this->team, $admin]), ['role' => 'member'])
            ->assertRedirect();

        $membership = $this->membership($admin);
        $this->assertSame(TeamRole::Member, $membership->role);
        $this->assertNull($membership->role_id);
        $this->assertFalse($authorize->execute($admin->fresh(), 'users.manage', $this->team));
    }
}
