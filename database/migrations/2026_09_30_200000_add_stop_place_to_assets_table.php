<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a unit stopped, and where its last stop alert was raised: a parked
 * unit's GPS reports phantom speeds of a few km/h, and without the stop's
 * place a jitter spike looked like a departure that reopened the episode and
 * re-alerted every ten minutes. A stop now ends only by leaving its place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->decimal('stop_latitude', 10, 7)->nullable();
            $table->decimal('stop_longitude', 10, 7)->nullable();
            $table->decimal('stop_alerted_latitude', 10, 7)->nullable();
            $table->decimal('stop_alerted_longitude', 10, 7)->nullable();
            // Last after-hours movement alert: one per unit per closed stretch.
            $table->timestamp('after_hours_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'stop_latitude',
                'stop_longitude',
                'stop_alerted_latitude',
                'stop_alerted_longitude',
                'after_hours_alerted_at',
            ]);
        });
    }
};
