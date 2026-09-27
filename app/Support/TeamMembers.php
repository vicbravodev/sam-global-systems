<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Pertenencia de usuarios a un tenant.
 *
 * Los ids de usuario son globales de plataforma: todo id que llega de un
 * tenant (asignar, notificar, pintar nombres) debe filtrarse por membresía
 * del team, o se pueden enumerar / contactar usuarios de otros tenants.
 */
class TeamMembers
{
    public static function isMember(int $teamId, int $userId): bool
    {
        return Membership::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Usuario asignable a un incidente del team: miembro, o super-admin
     * (operador de SAM dando soporte, que no es miembro del tenant).
     */
    public static function isAssignable(int $teamId, int $userId): bool
    {
        return self::isMember($teamId, $userId)
            || User::query()->whereKey($userId)->where('global_role', 'super_admin')->exists();
    }

    /**
     * Restringe una query de usuarios a los miembros del team (y, si se pide,
     * a los super-admins, que actúan sobre el tenant como operadores).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function scope(Builder $query, int $teamId, bool $includeSuperAdmins = false): Builder
    {
        return $query->where(function (Builder $q) use ($teamId, $includeSuperAdmins) {
            $q->whereExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from('team_members')
                ->whereColumn('team_members.user_id', 'users.id')
                ->where('team_members.team_id', $teamId));

            if ($includeSuperAdmins) {
                $q->orWhere('users.global_role', 'super_admin');
            }
        });
    }
}
