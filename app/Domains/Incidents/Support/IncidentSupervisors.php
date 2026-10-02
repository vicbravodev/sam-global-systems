<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Destinatarios por defecto de una escalación cuando el tenant no configuró
 * contactos: los miembros con permiso de gestión de incidentes
 * (`incidents.manage` — supervisores y admins), nunca el equipo entero.
 */
final class IncidentSupervisors
{
    public const string PERMISSION = 'incidents.manage';

    public const string ADMIN_PERMISSION = 'tenancy.manage';

    /**
     * @return array<int, array<string, mixed>> Entradas de `payload.recipients`.
     */
    public static function recipients(int $teamId): array
    {
        return self::recipientsFor(self::users($teamId));
    }

    /**
     * @param  iterable<User>  $users
     * @return array<int, array<string, mixed>> Entradas de `payload.recipients`.
     */
    public static function recipientsFor(iterable $users): array
    {
        $recipients = [];

        foreach ($users as $user) {
            $recipients[] = self::recipientFor($user);
        }

        return $recipients;
    }

    /**
     * @return array<string, mixed> Entrada de `payload.recipients`.
     */
    public static function recipientFor(User $user): array
    {
        return [
            'recipient_type' => 'user',
            'address' => $user->email,
            'email' => $user->email,
            'phone' => $user->verifiedPhone(),
            'name' => $user->name,
            'recipient_reference_id' => (string) $user->id,
        ];
    }

    /**
     * Miembros con correo que gestionan incidentes (supervisores y admins).
     *
     * @return array<int, User>
     */
    public static function users(int $teamId): array
    {
        $tiers = self::tiers($teamId);

        return [...$tiers['operations'], ...$tiers['admins']];
    }

    /**
     * Los mismos miembros, separados en dos escalones de la escalera:
     * `operations` (supervisores/monitoristas: gestionan incidentes pero no el
     * tenant) y `admins` (owner/admin del equipo o permiso `tenancy.manage`).
     *
     * @return array{operations: list<User>, admins: list<User>}
     */
    public static function tiers(int $teamId): array
    {
        $tiers = ['operations' => [], 'admins' => []];
        $team = Team::query()->find($teamId);

        if ($team === null) {
            return $tiers;
        }

        $authorize = app(AuthorizeAction::class);

        $memberships = TenantContext::for($teamId, fn () => Membership::query()
            ->with('user')
            ->where('team_id', $teamId)
            ->orderBy('id')
            ->get());

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if (! $user instanceof User || $user->email === '') {
                continue;
            }

            $permissions = $authorize->resolvePermissions($user, $team);

            if (! self::canManageIncidents($permissions, $membership)) {
                continue;
            }

            $tiers[self::isAdmin($permissions, $membership) ? 'admins' : 'operations'][] = $user;
        }

        return $tiers;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private static function canManageIncidents(array $permissions, Membership $membership): bool
    {
        if ($permissions !== []) {
            return in_array(self::PERMISSION, $permissions, true);
        }

        // Catálogo RBAC sin sembrar y membresía sin rol explícito: el rol de
        // equipo decide (owner/admin gestionan incidentes).
        return $membership->role_id === null
            && in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private static function isAdmin(array $permissions, Membership $membership): bool
    {
        return in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true)
            || in_array(self::ADMIN_PERMISSION, $permissions, true);
    }
}
