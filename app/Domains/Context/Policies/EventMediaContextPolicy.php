<?php

namespace App\Domains\Context\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Context\Models\EventMediaContext;
use App\Models\User;

class EventMediaContextPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'context.view', $team);
    }

    public function view(User $user, EventMediaContext $media): bool
    {
        $team = currentTeam();

        return $team !== null
            && $media->team_id === $team->id
            && $this->authorizeAction->execute($user, 'context.view', $team);
    }

    public function request(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'context.view', $team);
    }
}
