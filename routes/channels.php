<?php

use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Support\CurrentSubscription;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\EnsureTenantNotSuspended;
use App\Http\Middleware\RequireSuperAdminTwoFactor;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Support\Facades\Broadcast;

/**
 * ¿Puede el usuario escuchar el tiempo real de este tenant? Mismo criterio
 * que la consola web ({@see EnsureTeamMembership} + {@see EnsureTenantNotSuspended}):
 * el super-admin entra a cualquier tenant (a uno ajeno, con 2FA confirmado) y
 * no le afecta la suspensión; el resto debe ser miembro de un tenant no
 * suspendido.
 */
$canListenToTeam = function (User $user, Team $team, string $channel): bool {
    $reason = null;

    if ($user->isSuperAdmin()) {
        if (! $user->belongsToTeam($team) && RequireSuperAdminTwoFactor::mustSetUpTwoFactor($user)) {
            $reason = 'two_factor_required';
        }
    } elseif (! $user->belongsToTeam($team)) {
        $reason = 'not_member';
    } elseif (CurrentSubscription::isSuspended($team->id)) {
        $reason = 'tenant_suspended';
    }

    if ($reason === null) {
        return true;
    }

    SystemLog::skipped('broadcast.channel.denied', $reason, input: [
        'user_id' => $user->id,
        'team_id' => $team->id,
        'channel' => $channel,
    ]);

    return false;
};

Broadcast::channel('accounts.{teamId}', function (User $user, int $teamId) use ($canListenToTeam): bool {
    $team = Team::find($teamId);

    return $team !== null && $canListenToTeam($user, $team, 'accounts');
});

Broadcast::channel('users.{userId}', function ($user, int $userId) {
    return $user->id === $userId;
});

Broadcast::channel('incidents.{incidentId}', function (User $user, int $incidentId) use ($canListenToTeam): array|false {
    $incident = Incident::withoutGlobalScopes()->find($incidentId);

    if ($incident === null) {
        return false;
    }

    $team = Team::find($incident->team_id);

    if ($team === null || ! $canListenToTeam($user, $team, 'incidents')) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
