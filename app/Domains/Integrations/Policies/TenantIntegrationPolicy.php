<?php

namespace App\Domains\Integrations\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;

class TenantIntegrationPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'integrations.view', $team);
    }

    public function view(User $user, TenantIntegration $integration): bool
    {
        $team = currentTeam();

        return $team
            && $integration->team_id === $team->id
            && $this->authorizeAction->execute($user, 'integrations.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'integrations.manage', $team);
    }

    public function update(User $user, TenantIntegration $integration): bool
    {
        $team = currentTeam();

        return $team
            && $integration->team_id === $team->id
            && $this->authorizeAction->execute($user, 'integrations.manage', $team);
    }

    public function delete(User $user, TenantIntegration $integration): bool
    {
        $team = currentTeam();

        return $team
            && $integration->team_id === $team->id
            && $this->authorizeAction->execute($user, 'integrations.manage', $team);
    }
}
