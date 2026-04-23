<?php

namespace App\Domains\Context\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Context\Models\Geofence;
use App\Models\User;

class GeofencePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'geofences.view', $team);
    }

    public function view(User $user, Geofence $geofence): bool
    {
        $team = currentTeam();

        return $team
            && $geofence->team_id === $team->id
            && $this->authorizeAction->execute($user, 'geofences.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'geofences.manage', $team);
    }

    public function update(User $user, Geofence $geofence): bool
    {
        $team = currentTeam();

        return $team
            && $geofence->team_id === $team->id
            && $this->authorizeAction->execute($user, 'geofences.manage', $team);
    }

    public function delete(User $user, Geofence $geofence): bool
    {
        $team = currentTeam();

        return $team
            && $geofence->team_id === $team->id
            && $this->authorizeAction->execute($user, 'geofences.manage', $team);
    }
}
