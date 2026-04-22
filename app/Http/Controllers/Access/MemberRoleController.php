<?php

namespace App\Http\Controllers\Access;

use App\Domains\Access\Actions\AssignRoleToMember;
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
        $assignRoleToMember->execute($membership, $request->validated('role_code'));

        return back();
    }
}
