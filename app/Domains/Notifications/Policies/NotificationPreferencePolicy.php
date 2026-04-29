<?php

namespace App\Domains\Notifications\Policies;

use App\Domains\Notifications\Models\NotificationPreference;
use App\Models\User;

class NotificationPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return currentTeam() !== null;
    }

    public function update(User $user, ?NotificationPreference $preference = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($preference !== null) {
            return $preference->team_id === $team->id
                && ($preference->user_id === null || $preference->user_id === $user->id);
        }

        return true;
    }
}
