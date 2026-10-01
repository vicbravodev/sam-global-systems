<?php

namespace App\Http\Controllers\Access;

use App\Domains\Access\Actions\GuardRoleDelegation;
use App\Domains\Access\Actions\SyncRolePermissions;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\StoreRoleRequest;
use App\Http\Requests\Access\UpdateRoleRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private readonly GuardRoleDelegation $guard) {}

    public function index(Team $current_team, #[CurrentUser] User $user): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('settings/roles/index', [
            // Roles de sistema + los personalizados de ESTE tenant, nunca los
            // de otros tenants.
            'roles' => Role::tenant()
                ->visibleToTeam($current_team->id)
                ->with('permissions')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => $this->presentRole($role, $user))
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
                ->map(fn (Membership $membership) => $this->presentMember($membership, $user))
                ->all(),
        ]);
    }

    public function store(StoreRoleRequest $request, Team $current_team, SyncRolePermissions $syncRolePermissions, #[CurrentUser] User $user): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $this->guard->assertCanGrantPermissions($user, $current_team, $request->validated('permissions'));

        $role = Role::create([
            'team_id' => $current_team->id,
            'name' => $request->validated('name'),
            'code' => Role::customCodeFor($current_team->id, $request->validated('code')),
            'description' => $request->validated('description'),
            'scope' => RoleScope::Tenant,
            'is_system' => false,
        ]);

        $syncRolePermissions->execute($role, $request->validated('permissions'));

        return back(303);
    }

    public function update(UpdateRoleRequest $request, Team $current_team, Role $role, SyncRolePermissions $syncRolePermissions, #[CurrentUser] User $user): RedirectResponse
    {
        // Un rol de otro tenant no existe para este (404, sin filtrar su id).
        abort_unless($role->isVisibleToTeam($current_team->id), 404);

        $this->authorize('update', $role);

        abort_if($role->is_system && $request->has('name'), 403, 'Cannot rename a system role.');

        $this->guard->assertCanGrantPermissions($user, $current_team, $request->validated('permissions'));

        $role->update($request->safe()->only(['name', 'description']));

        $syncRolePermissions->execute($role, $request->validated('permissions'));

        return back(303);
    }

    public function destroy(Team $current_team, Role $role): RedirectResponse
    {
        abort_unless($role->isVisibleToTeam($current_team->id), 404);

        $this->authorize('delete', $role);

        abort_if($role->is_system, 403, 'System roles cannot be deleted.');

        $role->delete();

        return back(303);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRole(Role $role, User $user): array
    {
        return [
            'editable' => $user->can('update', $role),
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
    private function presentMember(Membership $membership, User $user): array
    {
        return [
            // El propietario y uno mismo no se editan desde aquí.
            'locked' => ! $user->isSuperAdmin() && (
                $membership->user_id === $user->id
                || $membership->getRawOriginal('role') === TeamRole::Owner->value
            ),
            'id' => $membership->id,
            'userName' => $membership->user?->name ?? '—',
            'userEmail' => $membership->user?->email ?? '',
            'roleCode' => $membership->accessRole?->code,
            'roleName' => $membership->accessRole?->name,
            'legacyRole' => $membership->getRawOriginal('role'),
        ];
    }
}
