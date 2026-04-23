<?php

namespace App\Domains\Context\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Models\User;

class EventContextPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'context.view', $team);
    }

    public function view(User $user, EventContextSnapshot $snapshot): bool
    {
        $team = currentTeam();

        return $team
            && $snapshot->team_id === $team->id
            && $this->authorizeAction->execute($user, 'context.view', $team);
    }
}
