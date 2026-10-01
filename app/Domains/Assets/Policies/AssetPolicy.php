<?php

namespace App\Domains\Assets\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Assets\Models\Asset;
use App\Models\User;

/**
 * Lectura de activos por la api. Compara el team explícitamente: no se
 * apoya sólo en el scope global de BelongsToTenant (CLAUDE.md §2.1 punto 6).
 */
class AssetPolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team !== null && $this->authorizeAction->execute($user, 'assets.view', $team);
    }

    public function view(User $user, Asset $asset): bool
    {
        $team = currentTeam();

        return $team !== null
            && $asset->team_id === $team->id
            && $this->authorizeAction->execute($user, 'assets.view', $team);
    }
}
