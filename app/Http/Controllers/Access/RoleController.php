<?php

namespace App\Http\Controllers\Access;

use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\StoreRoleRequest;
use App\Http\Requests\Access\UpdateRoleRequest;
use App\Models\Membership;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Team $current_team): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('settings/roles/index', [
            'roles' => Role::tenant()
                ->with('permissions')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => $this->presentRole($role))
                ->all(),
            'permissions' => fn () => Permission::query()
                ->orderBy('module')
                ->orderBy('name')
                ->get()
                ->groupBy('module')
                ->map(fn ($group) => $group->map(fn (Permission $permission) => [
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'description' => $permission->description,
                ])->values())
                ->toArray(),
            'members' => fn () => Membership::query()
                ->where('team_id', $current_team->id)
                ->with(['user:id,name,email', 'accessRole:id,name,code'])
                ->get()
                ->map(fn (Membership $membership) => $this->presentMember($membership))
                ->all(),
        ]);
    }

    public function store(StoreRoleRequest $request, SyncRolePermissions $syncRolePermissions): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $role = Role::create([
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'description' => $request->validated('description'),
            'scope' => RoleScope::Tenant,
            'is_system' => false,
        ]);

        $syncRolePermissions->execute($role, $request->validated('permissions'));

        return back(303);
    }

    public function update(UpdateRoleRequest $request, Team $current_team, Role $role, SyncRolePermissions $syncRolePermissions): RedirectResponse
    {
        $this->authorize('update', $role);

        abort_if($role->is_system && $request->has('name'), 403, 'Cannot rename a system role.');

        $role->update($request->safe()->only(['name', 'description']));

        $syncRolePermissions->execute($role, $request->validated('permissions'));

        return back(303);
    }

    public function destroy(Team $current_team, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        abort_if($role->is_system, 403, 'System roles cannot be deleted.');

        $role->delete();

        return back(303);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRole(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'code' => $role->code,
            'description' => $role->description,
            'isSystem' => $role->is_system,
            'permissions' => $role->permissions->pluck('code')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMember(Membership $membership): array
    {
        return [
            'id' => $membership->id,
            'userName' => $membership->user?->name ?? '—',
            'userEmail' => $membership->user?->email ?? '',
            'roleCode' => $membership->accessRole?->code,
            'roleName' => $membership->accessRole?->name,
            'legacyRole' => $membership->getRawOriginal('role'),
        ];
    }
}
