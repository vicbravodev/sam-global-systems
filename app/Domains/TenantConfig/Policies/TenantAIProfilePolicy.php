<?php

namespace App\Domains\TenantConfig\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\TenantConfig\Models\TenantAIProfile;
use App\Models\User;

class TenantAIProfilePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function view(User $user, ?TenantAIProfile $profile = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($profile !== null && $profile->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'config.view', $team);
    }

    public function update(User $user, ?TenantAIProfile $profile = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($profile !== null && $profile->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'config.manage', $team);
    }
}
