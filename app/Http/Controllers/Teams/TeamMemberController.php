<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\UpdateTeamMemberRole;
use App\Domains\Access\Actions\GuardRoleDelegation;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    /**
     * Update the specified team member's role.
     */
    public function update(
        UpdateTeamMemberRequest $request,
        Team $team,
        User $user,
        GuardRoleDelegation $guard,
        UpdateTeamMemberRole $updateTeamMemberRole,
    ): RedirectResponse {
        Gate::authorize('updateMember', $team);

        $newRole = TeamRole::from($request->validated('role'));

        $guard->assertCanChangeMembership($request->user(), $this->membership($team, $user));
        $guard->assertCanGrantTeamRole($request->user(), $team, $newRole);

        $updateTeamMemberRole->handle($team, $user, $newRole);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(Request $request, Team $team, User $user, GuardRoleDelegation $guard): RedirectResponse
    {
        Gate::authorize('removeMember', $team);

        // Ningún propietario (no sólo el primero) se quita desde el tenant.
        $guard->assertCanChangeMembership($request->user(), $this->membership($team, $user));

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($team)) {
            $user->switchTeam($user->personalTeam());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    private function membership(Team $team, User $user): Membership
    {
        return $team->memberships()->where('user_id', $user->id)->firstOrFail();
    }
}
