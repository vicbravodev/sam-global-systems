<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
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

        if ($target->user_id === $actor->id) {
            throw new AuthorizationException('No puedes cambiar tu propio rol.');
        }

        if ($target->getRawOriginal('role') === TeamRole::Owner->value) {
            throw new AuthorizationException('El propietario del tenant no se puede modificar ni quitar.');
        }

        $team = $target->team;
        $targetUser = $target->user;

        if ($team !== null && $targetUser !== null) {
            $this->assertHolds($actor, $team, $this->authorizeAction->resolvePermissions($targetUser, $team),
                'No puedes modificar a un miembro con más permisos que tú.');
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
            throw new AuthorizationException('No puedes asignar un rol superior al tuyo.');
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
        $this->assertHolds($actor, $team, $permissionCodes, 'No puedes conceder permisos que tú no tienes.');
    }

    /**
     * @param  array<string>  $permissionCodes
     *
     * @throws AuthorizationException
     */
    private function assertHolds(User $actor, Team $team, array $permissionCodes, string $message): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        // Códigos inexistentes no conceden nada; se ignoran.
        $known = Permission::query()->whereIn('code', $permissionCodes)->pluck('code')->all();
        $missing = array_diff($known, $this->authorizeAction->resolvePermissions($actor, $team));

        if ($missing !== []) {
            throw new AuthorizationException($message);
        }
    }
}
