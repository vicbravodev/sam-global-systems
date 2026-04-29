<?php

namespace App\Domains\Notifications\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Models\User;

class NotificationTemplatePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'notifications.view', $team);
    }

    public function manage(User $user, ?NotificationTemplate $template = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($template !== null && $template->team_id !== null && $template->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'notifications.manage', $team);
    }
}
