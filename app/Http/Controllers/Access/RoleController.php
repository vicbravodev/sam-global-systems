<?php

namespace App\Http\Controllers\Access;

use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\StoreRoleRequest;
use App\Http\Requests\Access\UpdateRoleRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Team $current_team): Response
    {
        return Inertia::render('settings/roles/index', [
            'roles' => Role::tenant()->with('permissions')->get(),
            'permissions' => Permission::all()->groupBy('module'),
        ]);
    }

    public function store(StoreRoleRequest $request, SyncRolePermissions $syncRolePermissions): RedirectResponse
    {
        $role = Role::create([
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'description' => $request->validated('description'),
            'scope' => RoleScope::Tenant,
            'is_system' => false,
        ]);

        $syncRolePermissions->execute($role, $request->validated('permissions'));

        return back();
    }

    public function update(UpdateRoleRequest $request, Team $current_team, Role $role, SyncRolePermissions $syncRolePermissions): RedirectResponse
    {
        abort_if($role->is_system && $request->has('name'), 403, 'Cannot rename a system role.');

        $role->update($request->safe()->only(['name', 'description']));

        $syncRolePermissions->execute($role, $request->validated('permissions'));

        return back();
    }

    public function destroy(Team $current_team, Role $role): RedirectResponse
    {
        abort_if($role->is_system, 403, 'System roles cannot be deleted.');

        $role->delete();

        return back();
    }
}
