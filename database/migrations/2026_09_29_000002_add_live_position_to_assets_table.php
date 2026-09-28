<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The current position and motion state live on the asset row: the live map
 * and the fleet list read one row per unit instead of a "latest snapshot" over
 * a table that grows by the minute, and the stop detector finds its candidates
 * with one indexed query instead of scanning every asset's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->decimal('last_speed_kph', 6, 2)->nullable();
            $table->smallInteger('last_heading')->nullable();
            $table->string('last_formatted_location')->nullable();
            $table->timestamp('last_location_at')->nullable();
            // Last point above the moving threshold; anchors a stop episode.
            $table->timestamp('last_moving_at')->nullable();
            // First still point after the last movement; null while moving.
            $table->timestamp('stopped_since')->nullable();
            // Anchor (`last_moving_at`) of the stop episode already alerted.
            $table->timestamp('stop_alerted_for')->nullable();

            $table->index(['team_id', 'stopped_since']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'stopped_since']);
            $table->dropColumn([
                'last_latitude',
                'last_longitude',
                'last_speed_kph',
                'last_heading',
                'last_formatted_location',
                'last_location_at',
                'last_moving_at',
                'stopped_since',
                'stop_alerted_for',
            ]);
        });
    }
};
