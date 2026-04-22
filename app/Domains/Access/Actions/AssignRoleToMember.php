<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Events\RoleAssigned;
use App\Domains\Access\Models\Role;
use App\Models\Membership;
use InvalidArgumentException;

class AssignRoleToMember
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function execute(Membership $membership, string $roleCode): void
    {
        $role = Role::where('code', $roleCode)->firstOrFail();

        if ($role->scope !== RoleScope::Tenant) {
            throw new InvalidArgumentException("Cannot assign a global-scope role [{$roleCode}] to a team member.");
        }

        $membership->update([
            'role_id' => $role->id,
            'role' => $this->mapToLegacyRole($roleCode),
        ]);

        $this->authorizeAction->invalidateCache($membership->user_id, $membership->team_id);

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
