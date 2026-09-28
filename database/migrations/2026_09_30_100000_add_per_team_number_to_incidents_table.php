<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant sequential incident number (UI audit P1-11).
 *
 * The displayed reference used to be the global `incidents.id`, which tells
 * any tenant how many incidents the whole platform has opened. `number` is
 * sequential inside each team; `teams.last_incident_number` is the counter
 * `IncidentNumberSequence` increments under a row lock. Existing incidents
 * are backfilled per team in id order, and the counter is set to the max.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->unsignedBigInteger('number')->nullable()->after('team_id');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_incident_number')->default(0);
        });

        DB::table('incidents')
            ->select('team_id')
            ->distinct()
            ->orderBy('team_id')
            ->pluck('team_id')
            ->each(function ($teamId): void {
                $number = 0;

                DB::table('incidents')
                    ->where('team_id', $teamId)
                    ->orderBy('id')
                    ->select('id')
                    ->chunkById(500, function ($rows) use (&$number): void {
                        foreach ($rows as $row) {
                            DB::table('incidents')->where('id', $row->id)->update(['number' => ++$number]);
                        }
                    });

                DB::table('teams')->where('id', $teamId)->update(['last_incident_number' => $number]);
            });

        Schema::table('incidents', function (Blueprint $table): void {
            $table->unique(['team_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropUnique(['team_id', 'number']);
            $table->dropColumn('number');
        });

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('last_incident_number');
        });
    }
};
