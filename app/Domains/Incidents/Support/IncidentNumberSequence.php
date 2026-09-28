<?php

namespace App\Domains\Incidents\Support;

use Illuminate\Support\Facades\DB;

/**
 * Hands out the per-tenant incident number (`incidents.number`).
 *
 * The counter lives on `teams.last_incident_number`. Incrementing it under a
 * row lock serialises concurrent creators of the same tenant (two workers
 * opening incidents at once get consecutive numbers) without touching other
 * tenants; the `(team_id, number)` unique index is the final guard.
 */
final class IncidentNumberSequence
{
    public static function next(int $teamId): int
    {
        return (int) DB::transaction(function () use ($teamId): int {
            $current = DB::table('teams')
                ->where('id', $teamId)
                ->lockForUpdate()
                ->value('last_incident_number');

            // A team created before the counter existed (or a counter reset
            // out of band) must never hand out a number already in use.
            $floor = (int) DB::table('incidents')->where('team_id', $teamId)->max('number');
            $next = max((int) $current, $floor) + 1;

            DB::table('teams')->where('id', $teamId)->update(['last_incident_number' => $next]);

            return $next;
        });
    }
}
