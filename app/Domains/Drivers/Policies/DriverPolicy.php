<?php

namespace App\Domains\Drivers\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Drivers\Models\Driver;
use App\Models\User;

class DriverPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'drivers.view', $team);
    }

    public function view(User $user, Driver $driver): bool
    {
        $team = currentTeam();

        return $team
            && $driver->team_id === $team->id
            && $this->authorizeAction->execute($user, 'drivers.view', $team);
    }

    public function updateContacts(User $user, Driver $driver): bool
    {
        $team = currentTeam();

        return $team
            && $driver->team_id === $team->id
            && $this->authorizeAction->execute($user, 'drivers.manage', $team);
    }

    public function updateDocuments(User $user, Driver $driver): bool
    {
        $team = currentTeam();

        return $team
            && $driver->team_id === $team->id
            && $this->authorizeAction->execute($user, 'drivers.manage', $team);
    }
}
