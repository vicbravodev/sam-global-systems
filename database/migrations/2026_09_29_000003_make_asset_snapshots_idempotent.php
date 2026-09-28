<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The telematics feed writes points with a bulk insert-or-ignore, which is
 * only idempotent if the database itself refuses a second copy of a point:
 * one GPS fix per (asset, instant) and one reading per (asset, type, instant).
 *
 * Pre-launch, so existing duplicates (if any) are collapsed to their oldest
 * row before the unique indexes go in. `recorded_at` also gets its own index
 * so the retention purge does not scan the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM asset_location_snapshots
            WHERE id NOT IN (
                SELECT MIN(id) FROM asset_location_snapshots GROUP BY asset_id, recorded_at
            )
        SQL);

        DB::statement(<<<'SQL'
            DELETE FROM asset_telemetry_snapshots
            WHERE id NOT IN (
                SELECT MIN(id) FROM asset_telemetry_snapshots GROUP BY asset_id, telemetry_type, recorded_at
            )
        SQL);

        Schema::table('asset_location_snapshots', function (Blueprint $table) {
            $table->dropIndex(['asset_id', 'recorded_at']);
            $table->unique(['asset_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        Schema::table('asset_telemetry_snapshots', function (Blueprint $table) {
            $table->dropIndex(['asset_id', 'telemetry_type', 'recorded_at']);
            $table->unique(['asset_id', 'telemetry_type', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('asset_telemetry_snapshots', function (Blueprint $table) {
            $table->dropUnique(['asset_id', 'telemetry_type', 'recorded_at']);
            $table->index(['asset_id', 'telemetry_type', 'recorded_at']);
        });

        Schema::table('asset_location_snapshots', function (Blueprint $table) {
            $table->dropIndex(['recorded_at']);
            $table->dropUnique(['asset_id', 'recorded_at']);
            $table->index(['asset_id', 'recorded_at']);
        });
    }
};
