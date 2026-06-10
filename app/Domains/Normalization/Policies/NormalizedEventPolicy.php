<?php

namespace App\Domains\Normalization\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;

/**
 * Gated on `context.view` — the operator-level permission for pipeline
 * visibility (context snapshots, media); normalized events are the same
 * surface, so no new permission code is introduced (Roadmap F10).
 */
class NormalizedEventPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'context.view', $team);
    }

    public function view(User $user, NormalizedEvent $event): bool
    {
        $team = currentTeam();

        return $team
            && $event->team_id === $team->id
            && $this->authorizeAction->execute($user, 'context.view', $team);
    }
}
