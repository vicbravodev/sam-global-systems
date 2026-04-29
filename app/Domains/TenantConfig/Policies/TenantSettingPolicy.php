<?php

namespace App\Domains\TenantConfig\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;

class TenantSettingPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'config.view', $team);
    }

    public function update(User $user, ?TenantSetting $setting = null): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($setting !== null && $setting->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'config.manage', $team);
    }
}
