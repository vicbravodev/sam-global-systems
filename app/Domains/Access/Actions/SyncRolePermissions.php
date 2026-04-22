<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Events\PermissionsSynced;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;

class SyncRolePermissions
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    /**
     * @param  array<string>  $permissionCodes
     */
    public function execute(Role $role, array $permissionCodes): void
    {
        $permissionIds = Permission::whereIn('code', $permissionCodes)->pluck('id');

        $role->permissions()->sync($permissionIds);

        $this->authorizeAction->invalidateCacheForRole($role);

        PermissionsSynced::dispatch($role, $permissionCodes);
    }
}
