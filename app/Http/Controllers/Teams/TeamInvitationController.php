<?php

namespace App\Http\Controllers\Teams;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Support\SystemLog;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $invitation = $team->invitations()->create([
            'email' => $request->validated('email'),
            'role' => TeamRole::from($request->validated('role')),
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        // Sin el email ni el código del enlace (es el secreto de la invitación).
        SystemLog::ok('access.invitation.created', input: [
            'team_id' => $team->id,
            'invited_by' => $user->id,
        ], result: [
            'invitation_id' => $invitation->id,
            'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $invitation->delete();

        SystemLog::ok('access.invitation.cancelled', input: [
            'team_id' => $team->id,
            'invitation_id' => $invitation->id,
            'actor_id' => auth()->id(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }
}
