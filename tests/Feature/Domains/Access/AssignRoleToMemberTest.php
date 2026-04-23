<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Events\RoleAssigned;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class AssignRoleToMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_role_to_member_updates_membership(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);

        $role = Role::factory()->create([
            'code' => 'supervisor',
            'scope' => RoleScope::Tenant,
        ]);

        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        Event::fake([RoleAssigned::class]);

        app(AssignRoleToMember::class)->execute($membership, 'supervisor');

        $membership->refresh();

        $this->assertEquals(
            $role->id,
            $membership->role_id,
            'Membership role_id should be updated to the assigned role ID',
        );

        $this->assertEquals(
            'admin',
            $membership->getRawOriginal('role'),
            'Membership legacy role column should be synced to "admin" for supervisor',
        );
    }

    public function test_cannot_assign_global_scope_role_to_member(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);

        Role::factory()->create([
            'code' => 'super_admin',
            'scope' => RoleScope::Global,
        ]);

        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot assign a global-scope role [super_admin] to a team member.');

        app(AssignRoleToMember::class)->execute($membership, 'super_admin');
    }

    public function test_tenant_admin_role_maps_to_legacy_admin(): void
    {
        Event::fake([RoleAssigned::class]);

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);

        Role::factory()->create([
            'code' => 'tenant_admin',
            'scope' => RoleScope::Tenant,
        ]);

        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        app(AssignRoleToMember::class)->execute($membership, 'tenant_admin');

        $membership->refresh();

        $this->assertEquals(
            'admin',
            $membership->getRawOriginal('role'),
            'tenant_admin role should map to legacy team role "admin"',
        );
    }

    public function test_unknown_role_code_maps_to_legacy_member(): void
    {
        Event::fake([RoleAssigned::class]);

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

        Role::factory()->create([
            'code' => 'viewer',
            'scope' => RoleScope::Tenant,
        ]);

        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        app(AssignRoleToMember::class)->execute($membership, 'viewer');

        $membership->refresh();

        $this->assertEquals(
            'member',
            $membership->getRawOriginal('role'),
            'Any non-admin role code should map to the legacy "member" team role',
        );
    }

    public function test_assign_role_dispatches_role_assigned_event(): void
    {
        Event::fake([RoleAssigned::class]);

        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);

        $role = Role::factory()->create([
            'code' => 'analyst',
            'scope' => RoleScope::Tenant,
        ]);

        $membership = Membership::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        app(AssignRoleToMember::class)->execute($membership, 'analyst');

        Event::assertDispatched(RoleAssigned::class, function (RoleAssigned $event) use ($membership, $role) {
            return $event->membership->id === $membership->id
                && $event->role->id === $role->id;
        });
    }
}
