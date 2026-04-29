<?php

namespace App\Domains\Incidents\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Incidents\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null
            && $this->authorizeAction->execute($user, 'incidents.view', $team);
    }

    public function view(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && $this->authorizeAction->execute($user, 'incidents.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team !== null
            && $this->authorizeAction->execute($user, 'incidents.manage', $team);
    }

    public function update(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && ! $incident->isTerminal()
            && $this->authorizeAction->execute($user, 'incidents.manage', $team);
    }

    public function assign(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && ! $incident->isTerminal()
            && $this->authorizeAction->execute($user, 'incidents.manage', $team);
    }

    public function attachEvidence(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && ! $incident->isTerminal()
            && $this->authorizeAction->execute($user, 'incidents.manage', $team);
    }

    public function comment(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && $this->authorizeAction->execute($user, 'incidents.manage', $team);
    }

    public function resolve(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && ! $incident->isTerminal()
            && $this->authorizeAction->execute($user, 'incidents.resolve', $team);
    }

    public function close(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && $this->authorizeAction->execute($user, 'incidents.close', $team);
    }

    public function reclassify(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && ! $incident->isTerminal()
            && $this->authorizeAction->execute($user, 'incidents.manage', $team);
    }

    public function linkEvent(User $user, Incident $incident): bool
    {
        $team = currentTeam();

        return $team !== null
            && $incident->team_id === $team->id
            && ! $incident->isTerminal()
            && $this->authorizeAction->execute($user, 'incidents.manage', $team);
    }
}
