<?php

namespace App\Actions\Teams;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
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
        return match (self::problemCode($invitation)) {
            'already_accepted' => 'Esta invitación ya fue utilizada.',
            'expired' => 'Esta invitación expiró. Pide a tu administrador una nueva.',
            'team_deleted' => 'La empresa de esta invitación ya no existe.',
            default => null,
        };
    }

    /**
     * El mismo motivo como código estable (para el log), o null si sirve.
     */
    public static function problemCode(TeamInvitation $invitation): ?string
    {
        return match (true) {
            $invitation->isAccepted() => 'already_accepted',
            $invitation->isExpired() => 'expired',
            $invitation->team === null => 'team_deleted',
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
                $this->reject(self::problemCode($invitation) ?? 'team_deleted', $user, $invitation);

                throw ValidationException::withMessages(['invitation' => $problem ?? 'La empresa de esta invitación ya no existe.']);
            }

            if (User::normalizeEmail($invitation->email) !== User::normalizeEmail($user->email)) {
                $this->reject('email_mismatch', $user, $invitation);

                throw ValidationException::withMessages([
                    'invitation' => 'Esta invitación fue enviada a otro correo electrónico.',
                ]);
            }

            $membership = $team->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role],
            );

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->authorizeAction->invalidateCache($user->id, $team->id);

            $user->switchTeam($team);

            // Tras el commit (el alta por invitación envuelve esto en otra
            // transacción con la creación de la cuenta).
            $membershipCreated = $membership->wasRecentlyCreated;
            DB::afterCommit(fn () => TenantContext::for($team->id, fn () => SystemLog::ok('access.invitation.accepted', input: [
                'team_id' => $team->id,
                'invitation_id' => $invitation->id,
                'user_id' => $user->id,
            ], result: [
                'role' => $invitation->role->value,
                'membership_created' => $membershipCreated,
                'membership_id' => $membership->id,
            ])));

            return $team;
        });
    }

    private function reject(string $reason, User $user, TeamInvitation $invitation): void
    {
        SystemLog::skipped('access.invitation.rejected', reason: $reason, input: [
            'team_id' => $invitation->team_id,
            'invitation_id' => $invitation->id,
            'user_id' => $user->id,
            'stage' => 'locked_recheck',
        ]);
    }
}
