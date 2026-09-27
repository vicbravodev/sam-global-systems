<?php

namespace App\Domains\Decisions\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Decisions\Models\DecisionRule;
use App\Models\User;

class DecisionRulePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'decisions.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'decisions.rules.manage', $team);
    }

    /**
     * Las reglas globales (team_id null) son de plataforma y aplican a todos
     * los tenants: sólo el super-admin las edita. Un tenant sólo muta las
     * suyas.
     */
    public function update(User $user, DecisionRule $rule): bool
    {
        if ($rule->team_id === null) {
            return $user->isSuperAdmin();
        }

        $team = currentTeam();

        return $team
            && (int) $rule->team_id === $team->id
            && $this->authorizeAction->execute($user, 'decisions.rules.manage', $team);
    }

    public function delete(User $user, DecisionRule $rule): bool
    {
        return $this->update($user, $rule);
    }
}
