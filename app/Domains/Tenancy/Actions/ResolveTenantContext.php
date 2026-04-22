<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Exceptions\TenantContextException;
use App\Models\Team;
use App\Models\User;

class ResolveTenantContext
{
    public function execute(User $user): Team
    {
        $team = $user->currentTeam;

        if (! $team) {
            throw TenantContextException::noTeamResolved();
        }

        return $team;
    }
}
