<?php

namespace App\Domains\Notifications\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Http\Controllers\Admin\GlobalChannelController;
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

    /**
     * Switch a SAM platform channel on/off for the current tenant. Tenants
     * never create, edit or delete channels — that is the super-admin
     * console's job ({@see GlobalChannelController}).
     */
    public function toggleGlobal(User $user, ?NotificationChannel $channel = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'notifications.manage', $team);
    }
}
