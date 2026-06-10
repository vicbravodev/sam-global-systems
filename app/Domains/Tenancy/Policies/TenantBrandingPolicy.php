<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Models\User;

class TenantBrandingPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function view(User $user, TenantBranding $branding): bool
    {
        $team = currentTeam();

        return $team
            && $branding->team_id === $team->id
            && $this->authorizeAction->execute($user, 'tenancy.manage', $team);
    }

    public function update(User $user, TenantBranding $branding): bool
    {
        $team = currentTeam();

        return $team
            && $branding->team_id === $team->id
            && $this->authorizeAction->execute($user, 'tenancy.manage', $team);
    }
}
