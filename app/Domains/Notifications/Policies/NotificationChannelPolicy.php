<?php

namespace App\Domains\Notifications\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\User;

class NotificationChannelPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'notifications.view', $team);
    }

    public function manage(User $user, ?NotificationChannel $channel = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($channel !== null && $channel->team_id !== null && $channel->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'notifications.manage', $team);
    }
}
