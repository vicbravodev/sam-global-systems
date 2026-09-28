<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The telemetry poll runs every minute and looks up the newest reading of every
 * (asset, type) pair of the fleet in one grouped query. The existing
 * (asset_id, recorded_at) index cannot serve a per-type maximum; this one can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_telemetry_snapshots', function (Blueprint $table) {
            $table->index(['asset_id', 'telemetry_type', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_telemetry_snapshots', function (Blueprint $table) {
            $table->dropIndex(['asset_id', 'telemetry_type', 'recorded_at']);
        });
    }
};
