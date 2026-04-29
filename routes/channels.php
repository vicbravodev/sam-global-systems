<?php

use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('accounts.{teamId}', function ($user, $teamId) {
    return $user->belongsToTeam(Team::findOrFail($teamId));
});

Broadcast::channel('jobs.{jobId}', function ($user, $jobId) {
    return $user !== null;
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
