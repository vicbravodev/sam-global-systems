<?php

namespace App\Domains\Analytics\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Analytics\Models\KpiRecord;
use App\Models\User;

class KpiRecordPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'reports.view', $team);
    }

    public function view(User $user, KpiRecord $kpi): bool
    {
        $team = currentTeam();

        return $team !== null
            && $kpi->team_id === $team->id
            && $this->authorizeAction->execute($user, 'reports.view', $team);
    }

    public function viewAiPerformance(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'ai.analysis.view', $team);
    }
}
