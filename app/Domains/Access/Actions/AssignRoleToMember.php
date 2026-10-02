<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Events\RoleAssigned;
use App\Domains\Access\Models\Role;
use App\Models\Membership;
use App\Support\SystemLog;
use InvalidArgumentException;

class AssignRoleToMember
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function execute(Membership $membership, string $roleCode): void
    {
        // Sólo roles de sistema o propios del team de la membresía: un rol
        // personalizado de otro tenant no existe para éste.
        $role = Role::query()
            ->visibleToTeam($membership->team_id)
            ->where('code', $roleCode)
            ->firstOrFail();

        if ($role->scope !== RoleScope::Tenant) {
            throw new InvalidArgumentException("Cannot assign a global-scope role [{$roleCode}] to a team member.");
        }

        $previousRoleId = $membership->role_id;

        $membership->update([
            'role_id' => $role->id,
            'role' => $this->mapToLegacyRole($roleCode),
        ]);

        $this->authorizeAction->invalidateCache($membership->user_id, $membership->team_id);

        // El código de un rol propio lo escribe el tenant: sólo se registra el
        // de los roles de sistema (catálogo fijo); el resto, por id.
        SystemLog::ok('access.member.role_assigned', input: [
            'team_id' => $membership->team_id,
            'user_id' => $membership->user_id,
            'membership_id' => $membership->id,
            'actor_id' => auth()->id(),
        ], result: [
            'previous_role_id' => $previousRoleId,
            'role_id' => $role->id,
            'role_code' => $role->is_system ? $role->code : null,
            'is_system_role' => $role->is_system,
            'legacy_role' => $membership->getRawOriginal('role'),
        ]);

        RoleAssigned::dispatch($membership, $role);
    }

    private function mapToLegacyRole(string $roleCode): string
    {
        return match ($roleCode) {
            'tenant_admin' => 'admin',
            'supervisor' => 'admin',
            default => 'member',
        };
    }
}
