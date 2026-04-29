<?php

namespace App\Domains\Automation\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Models\User;

class AutomationWorkflowPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'automation.view', $team);
    }

    public function view(User $user, AutomationWorkflow $workflow): bool
    {
        $team = currentTeam();
        if (! $team) {
            return false;
        }

        $belongsToTenantOrSystem = $workflow->team_id === null || $workflow->team_id === $team->id;

        return $belongsToTenantOrSystem
            && $this->authorizeAction->execute($user, 'automation.view', $team);
    }

    public function create(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'automation.manage', $team);
    }

    public function update(User $user, AutomationWorkflow $workflow): bool
    {
        $team = currentTeam();
        if (! $team) {
            return false;
        }

        return $workflow->team_id === $team->id
            && $this->authorizeAction->execute($user, 'automation.manage', $team);
    }

    public function delete(User $user, AutomationWorkflow $workflow): bool
    {
        return $this->update($user, $workflow);
    }

    public function trigger(User $user, AutomationWorkflow $workflow): bool
    {
        $team = currentTeam();
        if (! $team) {
            return false;
        }

        $belongsToTenantOrSystem = $workflow->team_id === null || $workflow->team_id === $team->id;

        return $belongsToTenantOrSystem
            && $this->authorizeAction->execute($user, 'automation.execute', $team);
    }
}
