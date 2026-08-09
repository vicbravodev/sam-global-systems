<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReleaseIncident
{
    /**
     * Suelta el incidente. Sólo lo consigue quien lo tenía tomado.
     */
    public function execute(Incident $incident, User $user): bool
    {
        return DB::transaction(function () use ($incident, $user) {
            $locked = Incident::query()
                ->whereKey($incident->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->claimed_by_user_id !== $user->id) {
                return false;
            }

            $locked->forceFill([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
            ])->save();

            return true;
        });
    }
}
