<?php

namespace App\Domains\Automation\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Automation\Models\ActionExecution;
use App\Models\User;

class ActionExecutionPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'automation.view', $team);
    }

    public function view(User $user, ActionExecution $execution): bool
    {
        $team = currentTeam();

        return $team !== null
            && $execution->team_id === $team->id
            && $this->authorizeAction->execute($user, 'automation.view', $team);
    }

    public function manage(User $user, ActionExecution $execution): bool
    {
        $team = currentTeam();

        return $team !== null
            && $execution->team_id === $team->id
            && $this->authorizeAction->execute($user, 'automation.execute', $team);
    }
}
