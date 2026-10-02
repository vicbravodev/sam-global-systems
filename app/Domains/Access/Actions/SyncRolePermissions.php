<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Events\PermissionsSynced;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Support\SystemLog;

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

        $changes = $role->permissions()->sync($permissionIds);

        $this->authorizeAction->invalidateCacheForRole($role);

        // Los códigos de permiso son un catálogo fijo; el nombre del rol no
        // (lo escribe el tenant), por eso sólo su id.
        SystemLog::ok('access.role.permissions_synced', input: [
            'team_id' => $role->team_id,
            'role_id' => $role->id,
            'is_system_role' => $role->is_system,
            'actor_id' => auth()->id(),
        ], calc: [
            'requested_count' => count($permissionCodes),
            'known_count' => $permissionIds->count(),
        ], result: [
            'attached_count' => count($changes['attached']),
            'detached_count' => count($changes['detached']),
            'memberships_invalidated' => $role->memberships()->count(),
        ]);

        PermissionsSynced::dispatch($role, $permissionCodes);
    }
}
