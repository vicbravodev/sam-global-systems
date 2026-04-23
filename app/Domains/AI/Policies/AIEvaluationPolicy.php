<?php

namespace App\Domains\AI\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\User;

class AIEvaluationPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'ai.analysis.view', $team);
    }

    public function view(User $user, AIEventEvaluation $evaluation): bool
    {
        $team = currentTeam();

        return $team
            && $evaluation->team_id === $team->id
            && $this->authorizeAction->execute($user, 'ai.analysis.view', $team);
    }

    public function reevaluate(User $user, AIEventEvaluation $evaluation): bool
    {
        $team = currentTeam();

        return $team
            && $evaluation->team_id === $team->id
            && $this->authorizeAction->execute($user, 'ai.analysis.execute', $team);
    }
}
