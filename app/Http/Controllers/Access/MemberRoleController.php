<?php

namespace App\Http\Controllers\Access;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Access\Actions\GuardRoleDelegation;
use App\Domains\Access\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\UpdateMemberRoleRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

class MemberRoleController extends Controller
{
    public function update(
        UpdateMemberRoleRequest $request,
        Team $current_team,
        Membership $membership,
        AssignRoleToMember $assignRoleToMember,
        GuardRoleDelegation $guard,
        #[CurrentUser] User $user,
    ): RedirectResponse {
        $this->authorize('assignRole', Role::class);

        // The implicit binding resolves memberships by global id. The
        // FormRequest already 404s a foreign membership before validating (no
        // existence oracle via 422); this re-check is defense in depth.
        abort_if($membership->team_id !== $current_team->id, 404);

        $role = Role::query()
            ->visibleToTeam($current_team->id)
            ->where('code', $request->validated('role_code'))
            ->firstOrFail();

        // Anti-escalada: ni propietarios, ni uno mismo, ni conceder más de
        // lo que el actor tiene.
        $guard->assertCanChangeMembership($user, $membership);
        $guard->assertCanGrantRole($user, $current_team, $role);

        $assignRoleToMember->execute($membership, $request->validated('role_code'));

        // 303 so fetch/browser follow-ups re-emit as GET (a 302 keeps the
        // PUT method and lands on the GET-only route as a 405).
        return back(303);
    }
}
