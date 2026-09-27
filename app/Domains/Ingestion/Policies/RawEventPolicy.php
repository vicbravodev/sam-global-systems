<?php

namespace App\Domains\Ingestion\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Ingestion\Models\RawEvent;
use App\Models\User;

/**
 * Eventos crudos: misma superficie de visibilidad del pipeline que los
 * normalizados (`context.view`, ver NormalizedEventPolicy). Compara el team
 * explícitamente.
 */
class RawEventPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'context.view', $team);
    }

    public function view(User $user, RawEvent $event): bool
    {
        $team = currentTeam();

        return $team
            && (int) $event->team_id === $team->id
            && $this->authorizeAction->execute($user, 'context.view', $team);
    }
}
