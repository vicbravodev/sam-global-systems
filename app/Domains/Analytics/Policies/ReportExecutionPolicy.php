<?php

namespace App\Domains\Analytics\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Analytics\Models\ReportExecution;
use App\Models\User;

class ReportExecutionPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'reports.view', $team);
    }

    public function view(User $user, ReportExecution $execution): bool
    {
        $team = currentTeam();

        return $team
            && $execution->team_id === $team->id
            && $this->authorizeAction->execute($user, 'reports.view', $team);
    }

    public function download(User $user, ReportExecution $execution): bool
    {
        $team = currentTeam();

        return $team
            && $execution->team_id === $team->id
            && $this->authorizeAction->execute($user, 'reports.export', $team);
    }
}
