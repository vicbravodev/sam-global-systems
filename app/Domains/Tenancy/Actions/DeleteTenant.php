<?php

namespace App\Domains\Tenancy\Actions;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Soft-deletes a tenant (Team uses SoftDeletes). Personal teams are never
 * deletable — they back the "every user has a personal team" invariant.
 */
class DeleteTenant
{
    public function execute(Team $team): void
    {
        if ($team->is_personal) {
            throw new RuntimeException('Personal teams cannot be deleted.');
        }

        DB::transaction(function () use ($team) {
            $team->delete();

            // Nadie se queda con un tenant borrado como team actual: con el
            // team soft-deleted, `currentTeam` resolvería null y el scope de
            // tenant quedaría sin filtro.
            User::query()
                ->where('current_team_id', $team->id)
                ->each(fn (User $user) => $user->switchAwayFrom($team));
        });
    }
}
