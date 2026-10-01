<?php

namespace App\Domains\Access\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'users.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'users.manage', $team);
    }

    /**
     * Los roles de sistema son catálogo de plataforma: cambiarlos cambiaría los
     * permisos de TODOS los tenants, así que sólo el super-admin puede. Un
     * tenant sólo edita sus propios roles personalizados.
     */
    public function update(User $user, Role $role): bool
    {
        return $this->managesRole($user, $role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->managesRole($user, $role);
    }

    private function managesRole(User $user, Role $role): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $team = currentTeam();

        return $team !== null
            && $role->isOwnedByTeam($team->id)
            && $this->authorizeAction->execute($user, 'users.manage', $team);
    }

    /**
     * Class-level ability used by MemberRoleController to gate changing the
     * role assigned to a team membership.
     */
    public function assignRole(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'users.manage', $team);
    }
}
