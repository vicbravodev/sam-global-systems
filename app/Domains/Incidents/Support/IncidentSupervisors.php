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

    /**
     * @return array<int, array<string, mixed>> Entradas de `payload.recipients`.
     */
    public static function recipients(int $teamId): array
    {
        $recipients = [];

        foreach (self::users($teamId) as $user) {
            $recipients[] = [
                'recipient_type' => 'user',
                'address' => (string) $user->email,
                'email' => (string) $user->email,
                'phone' => $user->verifiedPhone(),
                'name' => $user->name,
                'recipient_reference_id' => (string) $user->id,
            ];
        }

        return $recipients;
    }

    /**
     * Miembros con correo que gestionan incidentes (supervisores y admins).
     *
     * @return array<int, User>
     */
    public static function users(int $teamId): array
    {
        $team = Team::query()->find($teamId);

        if ($team === null) {
            return [];
        }

        $authorize = app(AuthorizeAction::class);

        $memberships = TenantContext::for($teamId, fn () => Membership::query()
            ->with('user')
            ->where('team_id', $teamId)
            ->get());

        $users = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if (! $user instanceof User || (string) $user->email === '') {
                continue;
            }

            if (! self::canManageIncidents($authorize, $membership, $user, $team)) {
                continue;
            }

            $users[] = $user;
        }

        return $users;
    }

    private static function canManageIncidents(AuthorizeAction $authorize, Membership $membership, User $user, Team $team): bool
    {
        $permissions = $authorize->resolvePermissions($user, $team);

        if ($permissions !== []) {
            return in_array(self::PERMISSION, $permissions, true);
        }

        // Catálogo RBAC sin sembrar y membresía sin rol explícito: el rol de
        // equipo decide (owner/admin gestionan incidentes).
        return $membership->role_id === null
            && in_array($membership->role, [TeamRole::Owner, TeamRole::Admin], true);
    }
}
