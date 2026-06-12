<?php

namespace App\Http\Controllers\Access;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Access\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\UpdateMemberRoleRequest;
use App\Models\Membership;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;

class MemberRoleController extends Controller
{
    public function update(
        UpdateMemberRoleRequest $request,
        Team $current_team,
        Membership $membership,
        AssignRoleToMember $assignRoleToMember,
    ): RedirectResponse {
        $this->authorize('assignRole', Role::class);

        // The implicit binding resolves memberships by global id; reject any
        // membership that does not belong to the current team (404 so the
        // existence of other teams' memberships is not leaked).
        abort_if($membership->team_id !== $current_team->id, 404);

        $assignRoleToMember->execute($membership, $request->validated('role_code'));

        // 303 so fetch/browser follow-ups re-emit as GET (a 302 keeps the
        // PUT method and lands on the GET-only route as a 405).
        return back(303);
    }
}
