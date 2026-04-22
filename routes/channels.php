<?php

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
