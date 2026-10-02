<?php

use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Support\CurrentSubscription;
use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('accounts.{teamId}', function ($user, int $teamId) {
    $team = Team::findOrFail($teamId);

    if (! $user->belongsToTeam($team)) {
        return false;
    }

    // Tenant suspendido: sin consola, tampoco tiempo real (los super-admins
    // sí, para dar soporte). Mismo criterio que EnsureTenantNotSuspended.
    return $user->isSuperAdmin() || ! CurrentSubscription::isSuspended($team->id);
});

Broadcast::channel('users.{userId}', function ($user, int $userId) {
    return $user->id === $userId;
});

Broadcast::channel('incidents.{incidentId}', function ($user, int $incidentId) {
    $incident = Incident::withoutGlobalScopes()->find($incidentId);

    if ($incident === null) {
        return false;
    }

    $team = Team::find($incident->team_id);

    if ($team === null || ! $user->belongsToTeam($team)) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
