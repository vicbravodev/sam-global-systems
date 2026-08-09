<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimIncident
{
    /**
     * Toma el incidente para este usuario. Devuelve false si ya lo tenía otro.
     * El bloqueo pesimista garantiza que en una carrera gane exactamente uno.
     */
    public function execute(Incident $incident, User $user): bool
    {
        return DB::transaction(function () use ($incident, $user) {
            $locked = Incident::query()
                ->whereKey($incident->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            if ($locked->claimed_by_user_id !== null && $locked->claimed_by_user_id !== $user->id) {
                return false;
            }

            $locked->forceFill([
                'claimed_by_user_id' => $user->id,
                'claimed_at' => now(),
            ])->save();

            return true;
        });
    }
}
