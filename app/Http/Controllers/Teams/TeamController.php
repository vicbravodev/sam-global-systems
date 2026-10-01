<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('teams/index', [
            'teams' => $user->toUserTeams(includeCurrent: true),
            // C3: solo el superadmin puede crear equipos/tenants.
            'canCreateTeam' => $user->isSuperAdmin(),
        ]);
    }

    /**
     * Store a newly created team.
     */
    public function store(SaveTeamRequest $request, CreateTeam $createTeam, #[CurrentUser] User $user): RedirectResponse
    {
        // C3: Team = tenant. La creación de equipos/tenants es exclusiva del
        // superadmin; un usuario normal ya no crea equipos desde su cuenta.
        abort_unless($user->isSuperAdmin(), 403);

        $team = $createTeam->handle($user, $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the team edit page.
     */
    public function edit(Team $team, #[CurrentUser] User $user): Response
    {
        return Inertia::render('teams/edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
            ],
            'members' => $team->members()->get()->map(function (User $member) {
                // El pivot (Membership) llega como relación hidratada por
                // BelongsToMany::using(); se lee tipado en vez de vía $pivot.
                $pivot = $member->getRelation('pivot');
                $role = $pivot instanceof Membership ? $pivot->role : null;

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $role?->value,
                    'role_label' => $role?->label(),
                ];
            }),
            // Solo pendientes: una invitación expirada ya no se puede aceptar
            // (y UniqueTeamInvitation permite volver a invitar ese email).
            'invitations' => $team->invitations()
                ->whereNull('accepted_at')
                ->where(fn ($query) => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()))
                ->get()
                // El `code` es el secreto del enlace de invitación: con él
                // cualquiera acepta en nombre del invitado. Nunca se envía al
                // navegador; la UI identifica la invitación por id.
                ->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'role_label' => $invitation->role->label(),
                    'created_at' => $invitation->created_at?->toISOString(),
                ]),
            'permissions' => $user->toTeamPermissions($team),
            'availableRoles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team = DB::transaction(function () use ($request, $team) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $request->validated('name')]);

            return $team;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Switch the user's current team.
     */
    public function switch(Team $team, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($user->belongsToTeam($team), 403);

        $user->switchTeam($team);

        return back();
    }

    /**
     * Delete the specified team.
     */
    public function destroy(DeleteTeamRequest $request, Team $team, #[CurrentUser] User $user): RedirectResponse
    {
        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        DB::transaction(function () use ($user, $team) {
            User::where('current_team_id', $team->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchAwayFrom($team));

            $team->invitations()->delete();
            $team->memberships()->delete();
            $team->delete();
        });

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('teams.index');
    }
}
