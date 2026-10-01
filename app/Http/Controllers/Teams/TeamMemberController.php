<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\UpdateTeamMemberRole;
use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Actions\GuardRoleDelegation;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
        #[CurrentUser] User $actor,
    ): RedirectResponse {
        Gate::authorize('updateMember', $team);

        $newRole = TeamRole::from($request->validated('role'));

        $guard->assertCanChangeMembership($actor, $this->membership($team, $user));
        $guard->assertCanGrantTeamRole($actor, $team, $newRole);

        $updateTeamMemberRole->handle($team, $user, $newRole);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Remove the specified team member.
     */
    public function destroy(
        Team $team,
        User $user,
        GuardRoleDelegation $guard,
        AuthorizeAction $authorizeAction,
        #[CurrentUser] User $actor,
    ): RedirectResponse {
        Gate::authorize('removeMember', $team);

        // Ningún propietario (no sólo el primero) se quita desde el tenant.
        $guard->assertCanChangeMembership($actor, $this->membership($team, $user));

        DB::transaction(function () use ($team, $user, $authorizeAction) {
            $team->memberships()
                ->where('user_id', $user->id)
                ->delete();

            $user->switchAwayFrom($team);

            $authorizeAction->invalidateCache($user->id, $team->id);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    private function membership(Team $team, User $user): Membership
    {
        return $team->memberships()->where('user_id', $user->id)->firstOrFail();
    }
}
