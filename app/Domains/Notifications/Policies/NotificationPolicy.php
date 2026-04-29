<?php

namespace App\Domains\Notifications\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;

class NotificationPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'notifications.view', $team);
    }

    public function view(User $user, Notification $notification): bool
    {
        $team = currentTeam();

        return $team
            && $notification->team_id === $team->id
            && $this->authorizeAction->execute($user, 'notifications.view', $team);
    }

    public function send(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'notifications.send', $team);
    }

    public function manage(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'notifications.manage', $team);
    }
}
