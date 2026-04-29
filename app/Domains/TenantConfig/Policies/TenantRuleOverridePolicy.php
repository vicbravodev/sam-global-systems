<?php

namespace App\Domains\TenantConfig\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\TenantConfig\Models\TenantRuleOverride;
use App\Models\User;

class TenantRuleOverridePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'config.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'config.manage', $team);
    }

    public function update(User $user, TenantRuleOverride $override): bool
    {
        $team = currentTeam();

        return $team
            && $override->team_id === $team->id
            && $this->authorizeAction->execute($user, 'config.manage', $team);
    }

    public function delete(User $user, TenantRuleOverride $override): bool
    {
        return $this->update($user, $override);
    }
}
