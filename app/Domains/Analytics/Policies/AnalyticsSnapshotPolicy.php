<?php

namespace App\Domains\Analytics\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Models\User;

class AnalyticsSnapshotPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'reports.view', $team);
    }

    public function view(User $user, AnalyticsSnapshot $snapshot): bool
    {
        $team = currentTeam();

        return $team !== null
            && $snapshot->team_id === $team->id
            && $this->authorizeAction->execute($user, 'reports.view', $team);
    }
}
