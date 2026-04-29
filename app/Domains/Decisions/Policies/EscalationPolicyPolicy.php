<?php

namespace App\Domains\Decisions\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Models\User;

class EscalationPolicyPolicy
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

        return $team && $this->authorizeAction->execute($user, 'decisions.escalation.manage', $team);
    }

    public function update(User $user, EscalationPolicy $policy): bool
    {
        $team = currentTeam();

        return $team
            && $policy->team_id === $team->id
            && $this->authorizeAction->execute($user, 'decisions.escalation.manage', $team);
    }
}
