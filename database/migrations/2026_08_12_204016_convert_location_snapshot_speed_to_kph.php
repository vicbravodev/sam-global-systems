<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: `asset_location_snapshots.speed` was stored in miles per hour.
 *
 * Samsara reports every speed in mph (`VehicleLocationSpeed`: "GPS speed of the
 * vehicle in miles per hour"), but the adapter persisted the raw value while
 * the UI labelled it km/h and `BuildEventContext` fed it into a field named
 * `speed_kph`. The adapter now converts at the provider boundary, so the rows
 * written before that change have to be converted once to match.
 *
 * DEPLOY ORDER: run this migration *before* restarting the queue workers. While
 * the workers still run the old code they keep writing mph, and those rows
 * would be missed; once they run the new code they write km/h, and re-running
 * this conversion over them would double-count. Laravel's `migrations` table
 * already guarantees this runs exactly once.
 *
 * Historical `event_context_snapshots` are intentionally left untouched: they
 * record what the AI actually saw at evaluation time, and rewriting them would
 * falsify that record.
 */
return new class extends Migration
{
    private const MILES_TO_KM = 1.609344;

    public function up(): void
    {
        DB::table('asset_location_snapshots')
            ->whereNotNull('speed')
            ->update(['speed' => DB::raw('ROUND(speed * '.self::MILES_TO_KM.', 2)')]);
    }

    public function down(): void
    {
        DB::table('asset_location_snapshots')
            ->whereNotNull('speed')
            ->update(['speed' => DB::raw('ROUND(speed / '.self::MILES_TO_KM.', 2)')]);
    }
};
