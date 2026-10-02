<?php

namespace App\Domains\Tenancy\Actions;

use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
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
            SystemLog::skipped('tenancy.tenant.deleted', reason: 'personal_team', input: ['team_id' => $team->id, 'actor_id' => auth()->id()]);

            throw new RuntimeException('Personal teams cannot be deleted.');
        }

        $switchedUsers = DB::transaction(function () use ($team): int {
            $team->delete();

            // Nadie se queda con un tenant borrado como team actual: con el
            // team soft-deleted, `currentTeam` resolvería null y el scope de
            // tenant quedaría sin filtro.
            $switched = 0;
            User::query()
                ->where('current_team_id', $team->id)
                ->each(function (User $user) use ($team, &$switched): void {
                    $user->switchAwayFrom($team);
                    $switched++;
                });

            return $switched;
        });

        SystemLog::ok('tenancy.tenant.deleted', input: ['team_id' => $team->id, 'actor_id' => auth()->id()], result: [
            'soft_deleted' => true,
            'users_switched_away' => $switchedUsers,
        ]);
    }
}
