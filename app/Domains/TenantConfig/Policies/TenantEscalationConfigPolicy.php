<?php

namespace App\Domains\TenantConfig\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Models\User;

class TenantEscalationConfigPolicy
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

    public function update(User $user, TenantEscalationConfig $config): bool
    {
        $team = currentTeam();

        return $team
            && $config->team_id === $team->id
            && $this->authorizeAction->execute($user, 'config.manage', $team);
    }
}
