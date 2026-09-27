<?php

namespace App\Actions\Teams;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

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
        $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role' => $role, 'role_id' => null]);

        $this->authorizeAction->invalidateCache((int) $user->id, (int) $team->id);
    }
}
