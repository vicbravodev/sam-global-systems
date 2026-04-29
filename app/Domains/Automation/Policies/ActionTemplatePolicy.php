<?php

namespace App\Domains\Automation\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Automation\Models\ActionTemplate;
use App\Models\User;

class ActionTemplatePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'automation.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'automation.manage', $team);
    }

    public function update(User $user, ActionTemplate $template): bool
    {
        $team = currentTeam();
        if (! $team) {
            return false;
        }

        return $template->team_id === $team->id
            && $this->authorizeAction->execute($user, 'automation.manage', $team);
    }
}
