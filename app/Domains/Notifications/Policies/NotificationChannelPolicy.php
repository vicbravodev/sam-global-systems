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

        // SAM platform channels (team_id null) are managed only from the
        // super-admin console (Roadmap V2-B1): a tenant can never edit, test
        // or delete them — only toggle them on/off for itself.
        if ($channel !== null && $channel->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'notifications.manage', $team);
    }

    /**
     * Switch a SAM platform channel on/off for the current tenant.
     */
    public function toggleGlobal(User $user, NotificationChannel $channel): bool
    {
        $team = currentTeam();

        if (! $team || $channel->team_id !== null) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'notifications.manage', $team);
    }
}
