<?php

namespace App\Actions\Teams;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el rol "de equipo" (owner/admin/member) de una membresía.
 *
 * AuthorizeAction prioriza `role_id` (RBAC) sobre el rol legado, así que si
 * sólo se tocara `team_members.role` una degradación no quitaría permisos. Se
 * limpia `role_id` para que el rol legado vuelva a mandar (fallback owner →
 * tenant_admin, admin → supervisor, member → viewer) y se invalida la caché.
 */
class UpdateTeamMemberRole
{
    public function __construct(private readonly AuthorizeAction $authorizeAction) {}

    public function handle(Team $team, User $user, TeamRole $role): void
    {
        $membership = $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail();

        $previousRole = $membership->getRawOriginal('role');
        $previousRoleId = $membership->role_id;

        $membership->update(['role' => $role, 'role_id' => null]);

        $this->authorizeAction->invalidateCache($user->id, $team->id);

        // Tras el commit: el cambio de propietario corre en la transacción del
        // llamador y no debe narrarse si se revierte.
        DB::afterCommit(fn () => TenantContext::for($team->id, fn () => SystemLog::ok('access.member.role_changed', input: [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'actor_id' => auth()->id(),
        ], result: [
            'previous_role' => $previousRole,
            'role' => $role->value,
            'rbac_role_cleared' => $previousRoleId !== null,
        ])));
    }
}
