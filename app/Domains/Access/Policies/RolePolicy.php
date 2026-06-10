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

        return $team && $this->authorizeAction->execute($user, 'users.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'users.manage', $team);
    }

    public function update(User $user, Role $role): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'users.manage', $team);
    }

    public function delete(User $user, Role $role): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'users.manage', $team);
    }

    /**
     * Class-level ability used by MemberRoleController to gate changing the
     * role assigned to a team membership.
     */
    public function assignRole(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'users.manage', $team);
    }
}
