<?php

namespace App\Domains\Analytics\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Models\User;

class ReportDefinitionPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'reports.view', $team);
    }

    public function generate(User $user, ReportDefinition $definition): bool
    {
        $team = currentTeam();

        if (! $team) {
            return false;
        }

        if ($definition->team_id !== null && $definition->team_id !== $team->id) {
            return false;
        }

        return $this->authorizeAction->execute($user, 'reports.export', $team);
    }
}
