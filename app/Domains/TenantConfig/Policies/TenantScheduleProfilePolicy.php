<?php

namespace App\Domains\TenantConfig\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\User;

class TenantScheduleProfilePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'config.view', $team);
    }

    public function update(User $user, TenantScheduleProfile $profile): bool
    {
        $team = currentTeam();

        return $team
            && $profile->team_id === $team->id
            && $this->authorizeAction->execute($user, 'config.manage', $team);
    }
}
