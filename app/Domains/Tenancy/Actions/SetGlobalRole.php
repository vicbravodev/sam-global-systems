<?php

namespace App\Domains\Tenancy\Actions;

use App\Models\User;

/**
 * Grants or revokes the global super-admin role. Single source of truth shared
 * by the CLI command and the operator console.
 */
class SetGlobalRole
{
    public function execute(User $user, bool $superAdmin): User
    {
        $user->update(['global_role' => $superAdmin ? 'super_admin' : null]);

        return $user;
    }
}
