<?php

namespace App\Domains\Decisions\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Decisions\Models\Decision;
use App\Models\User;

class DecisionPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'decisions.view', $team);
    }

    public function view(User $user, Decision $decision): bool
    {
        $team = currentTeam();

        return $team
            && $decision->team_id === $team->id
            && $this->authorizeAction->execute($user, 'decisions.view', $team);
    }

    public function override(User $user, Decision $decision): bool
    {
        $team = currentTeam();

        return $team
            && $decision->team_id === $team->id
            && $this->authorizeAction->execute($user, 'decisions.override', $team);
    }
}
