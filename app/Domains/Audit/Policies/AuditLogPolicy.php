<?php

namespace App\Domains\Audit\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Audit\Models\AuditLog;
use App\Models\User;

/**
 * Read-only policy. Audit data is append-only, so there is intentionally
 * no `update`, `delete`, `create`, or `restore` ability.
 */
class AuditLogPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'audit.view', $team);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        $team = currentTeam();

        return $team
            && $auditLog->team_id === $team->id
            && $this->authorizeAction->execute($user, 'audit.view', $team);
    }
}
