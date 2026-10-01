<?php

namespace App\Actions\Teams;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consume una invitación: crea la membresía con el rol invitado, marca la
 * invitación como usada y cambia al usuario a ese team. Bloquea la fila para
 * que dos envíos simultáneos no la consuman dos veces.
 */
class AcceptTeamInvitation
{
    public function __construct(private readonly AuthorizeAction $authorizeAction) {}

    /**
     * Motivo por el que la invitación ya no sirve, o null si es utilizable.
     */
    public static function problem(TeamInvitation $invitation): ?string
    {
        return match (true) {
            $invitation->isAccepted() => 'Esta invitación ya fue utilizada.',
            $invitation->isExpired() => 'Esta invitación expiró. Pide a tu administrador una nueva.',
            $invitation->team === null => 'La empresa de esta invitación ya no existe.',
            default => null,
        };
    }

    public function handle(User $user, TeamInvitation $invitation): Team
    {
        return DB::transaction(function () use ($user, $invitation) {
            $invitation = TeamInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            $team = $invitation->team;

            // problem() ya cubre el team borrado; el chequeo explícito lo hace visible al tipo.
            if (($problem = self::problem($invitation)) !== null || $team === null) {
                throw ValidationException::withMessages(['invitation' => $problem ?? 'La empresa de esta invitación ya no existe.']);
            }

            if (User::normalizeEmail($invitation->email) !== User::normalizeEmail($user->email)) {
                throw ValidationException::withMessages([
                    'invitation' => 'Esta invitación fue enviada a otro correo electrónico.',
                ]);
            }

            $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->authorizeAction->invalidateCache((int) $user->id, (int) $team->id);

            $user->switchTeam($team);

            return $team;
        });
    }
}
