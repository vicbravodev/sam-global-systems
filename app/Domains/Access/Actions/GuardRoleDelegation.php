<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Reglas anti-escalada al delegar acceso dentro de un tenant:
 *
 *  - nadie modifica ni quita a un propietario (la propiedad la reasigna el
 *    super-admin desde la consola);
 *  - nadie cambia su propio rol;
 *  - nadie concede un permiso que no tiene, ni toca a quien tiene más que él.
 *
 * El super-admin (operador SaaS) queda fuera de estas reglas.
 */
class GuardRoleDelegation
{
    public function __construct(private readonly AuthorizeAction $authorizeAction) {}

    /**
     * @throws AuthorizationException
     */
    public function assertCanChangeMembership(User $actor, Membership $target): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $input = ['actor_id' => $actor->id, 'team_id' => $target->team_id, 'target_user_id' => $target->user_id, 'check' => 'change_membership'];

        if ($target->user_id === $actor->id) {
            $this->deny('self_change', $input, 'No puedes cambiar tu propio rol.');
        }

        if ($target->getRawOriginal('role') === TeamRole::Owner->value) {
            $this->deny('owner_protected', $input, 'El propietario del tenant no se puede modificar ni quitar.');
        }

        $team = $target->team;
        $targetUser = $target->user;

        if ($team !== null && $targetUser !== null) {
            $this->assertHolds($actor, $team, $this->authorizeAction->resolvePermissions($targetUser, $team),
                'No puedes modificar a un miembro con más permisos que tú.', 'target_outranks_actor', $input);
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanGrantTeamRole(User $actor, Team $team, TeamRole $role): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $own = $actor->teamRole($team);

        if ($role === TeamRole::Owner || $own === null || ! $own->isAtLeast($role)) {
            $this->deny('role_above_own', [
                'actor_id' => $actor->id,
                'team_id' => $team->id,
                'check' => 'grant_team_role',
                'requested_role' => $role->value,
                'actor_role' => $own?->value,
            ], 'No puedes asignar un rol superior al tuyo.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCanGrantRole(User $actor, Team $team, Role $role): void
    {
        $this->assertCanGrantPermissions($actor, $team, $role->permissions()->pluck('code')->all());
    }

    /**
     * @param  array<string>  $permissionCodes
     *
     * @throws AuthorizationException
     */
    public function assertCanGrantPermissions(User $actor, Team $team, array $permissionCodes): void
    {
        $this->assertHolds($actor, $team, $permissionCodes, 'No puedes conceder permisos que tú no tienes.', 'permissions_not_held', [
            'actor_id' => $actor->id,
            'team_id' => $team->id,
            'check' => 'grant_permissions',
        ]);
    }

    /**
     * @param  array<string>  $permissionCodes
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     */
    private function assertHolds(User $actor, Team $team, array $permissionCodes, string $message, string $reason, array $input): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        // Códigos inexistentes no conceden nada; se ignoran.
        $known = Permission::query()->whereIn('code', $permissionCodes)->pluck('code')->all();
        $missing = array_diff($known, $this->authorizeAction->resolvePermissions($actor, $team));

        if ($missing !== []) {
            $this->deny($reason, $input, $message, [
                'requested_count' => count($known),
                'missing_permissions' => array_values($missing),
            ]);
        }
    }

    /**
     * Registra el intento bloqueado de escalada y lo corta con 403.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $calc
     *
     * @throws AuthorizationException
     */
    private function deny(string $reason, array $input, string $message, ?array $calc = null): never
    {
        SystemLog::skipped('access.role_delegation.denied', reason: $reason, input: $input, calc: $calc);

        throw new AuthorizationException($message);
    }
}
