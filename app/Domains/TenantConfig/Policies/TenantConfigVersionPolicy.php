<?php

namespace App\Domains\TenantConfig\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Models\User;

class TenantConfigVersionPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'config.view', $team);
    }

    public function view(User $user, TenantConfigVersion $version): bool
    {
        $team = currentTeam();

        return $team !== null
            && $version->team_id === $team->id
            && $this->authorizeAction->execute($user, 'config.view', $team);
    }
}
