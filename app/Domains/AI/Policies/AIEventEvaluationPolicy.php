<?php

namespace App\Domains\AI\Policies;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\User;

class AIEventEvaluationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    public function view(User $user, AIEventEvaluation $evaluation): bool
    {
        return $user->currentTeam !== null
            && $user->currentTeam->id === $evaluation->team_id;
    }
}
