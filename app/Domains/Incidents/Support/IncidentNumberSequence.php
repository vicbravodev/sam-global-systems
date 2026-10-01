<?php

namespace App\Domains\Incidents\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hands out the per-tenant incident number (`incidents.number`).
 *
 * The counter lives on `teams.last_incident_number`. Incrementing it under a
 * row lock serialises concurrent creators of the same tenant (two workers
 * opening incidents at once get consecutive numbers) without touching other
 * tenants; the `(team_id, number)` unique index is the final guard.
 *
 * On PostgreSQL the lock is `FOR NO KEY UPDATE`, not `FOR UPDATE`: every
 * insert into a table with a `team_id` foreign key takes `FOR KEY SHARE` on
 * the team row, which `FOR UPDATE` blocks. Since the caller holds this lock
 * until its incident transaction commits, `FOR UPDATE` stalled all of the
 * tenant's ingestion/normalization/usage inserts behind every incident.
 */
final class IncidentNumberSequence
{
    /**
     * @return positive-int
     */
    public static function next(int $teamId): int
    {
        $number = DB::transaction(function () use ($teamId): int {
            $current = DB::table('teams')
                ->where('id', $teamId)
                ->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)
                ->value('last_incident_number');

            // A team created before the counter existed (or a counter reset
            // out of band) must never hand out a number already in use.
            $floor = (int) DB::table('incidents')->where('team_id', $teamId)->max('number');
            $next = max((int) $current, $floor) + 1;

            DB::table('teams')->where('id', $teamId)->update(['last_incident_number' => $next]);

            return $next;
        });

        // El contador y los números existentes nunca son negativos: un número
        // menor a 1 sólo puede venir de un contador corrompido fuera de banda.
        if ($number < 1) {
            throw new LogicException("Secuencia de incidentes inválida para el team {$teamId}: {$number}.");
        }

        return $number;
    }
}
