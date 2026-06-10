<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\User;

class TenantFeaturePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'tenancy.manage', $team);
    }

    public function view(User $user, TenantFeature $feature): bool
    {
        $team = currentTeam();

        return $team
            && $feature->team_id === $team->id
            && $this->authorizeAction->execute($user, 'tenancy.manage', $team);
    }

    public function update(User $user, TenantFeature $feature): bool
    {
        $team = currentTeam();

        return $team
            && $feature->team_id === $team->id
            && $this->authorizeAction->execute($user, 'tenancy.manage', $team);
    }
}
