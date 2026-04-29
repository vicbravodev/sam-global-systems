<?php

namespace App\Domains\Decisions\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Decisions\Models\DecisionRule;
use App\Models\User;

class DecisionRulePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'decisions.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'decisions.rules.manage', $team);
    }

    public function update(User $user, DecisionRule $rule): bool
    {
        $team = currentTeam();

        return $team
            && ($rule->team_id === null || $rule->team_id === $team->id)
            && $this->authorizeAction->execute($user, 'decisions.rules.manage', $team);
    }

    public function delete(User $user, DecisionRule $rule): bool
    {
        return $this->update($user, $rule);
    }
}
