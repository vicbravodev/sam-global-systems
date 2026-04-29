<?php

namespace App\Domains\TenantConfig\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Models\User;

class TenantNotificationPolicyPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'config.view', $team);
    }

    public function update(User $user, ?TenantNotificationPolicy $policy = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($policy !== null && $policy->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'config.manage', $team);
    }
}
