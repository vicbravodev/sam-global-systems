<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Support\IncidentSupervisors;
use App\Models\User;

/**
 * A quién se avisa en un escalón del tenant (decisión 2026-10-01):
 *
 * - `on_call`: la persona en turno (ResolveOnCallOperator). Sin nadie en turno
 *   cae directo a `operations` (o `admins` si no hay operación).
 * - `operations`: quienes gestionan incidentes sin administrar el tenant
 *   (supervisores, monitoristas).
 * - `admins`: owner/admin del equipo o permiso `tenancy.manage`.
 *
 * Un escalón vacío cae al otro, para que un aviso nunca se quede sin nadie
 * mientras alguien del equipo pueda gestionar incidentes. Lo usan la escalera
 * de SLA (NotifyEscalationLevel) y el aviso de incidente creado.
 */
class ResolveEscalationAudience
{
    /** @var list<string> */
    public const array AUDIENCES = ['on_call', 'operations', 'admins'];

    public function __construct(
        private readonly ResolveOnCallOperator $resolveOnCallOperator,
    ) {}

    /**
     * Escalón por defecto de un nivel: 0 → en turno, 1 → operación, 2+ → admins.
     */
    public static function defaultFor(int $level): string
    {
        return match (true) {
            $level <= 0 => 'on_call',
            $level === 1 => 'operations',
            default => 'admins',
        };
    }

    /**
     * @return array{recipients: array<int, array<string, mixed>>, user_ids: list<int>, audience: string, requested: string, fallback: bool}
     */
    public function execute(int $teamId, string $requested): array
    {
        if ($requested === 'on_call') {
            $userId = $this->resolveOnCallOperator->execute($teamId);
            $user = $userId !== null ? User::query()->find($userId) : null;

            if ($user !== null && $user->email !== '') {
                return $this->result([$user], 'on_call', $requested, false);
            }

            $tiers = IncidentSupervisors::tiers($teamId);

            return $tiers['operations'] !== []
                ? $this->result($tiers['operations'], 'operations', $requested, true)
                : $this->result($tiers['admins'], 'admins', $requested, true);
        }

        $tier = $requested === 'admins' ? 'admins' : 'operations';
        $tiers = IncidentSupervisors::tiers($teamId);

        if ($tiers[$tier] !== []) {
            return $this->result($tiers[$tier], $tier, $requested, false);
        }

        $other = $tier === 'operations' ? 'admins' : 'operations';

        return $this->result($tiers[$other], $other, $requested, true);
    }

    /**
     * @param  array<int, User>  $users
     * @return array{recipients: array<int, array<string, mixed>>, user_ids: list<int>, audience: string, requested: string, fallback: bool}
     */
    private function result(array $users, string $audience, string $requested, bool $fallback): array
    {
        return [
            'recipients' => IncidentSupervisors::recipientsFor($users),
            'user_ids' => array_values(array_map(fn (User $user): int => $user->id, $users)),
            'audience' => $audience,
            'requested' => $requested,
            'fallback' => $fallback,
        ];
    }
}
