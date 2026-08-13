<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemetry polls on a slower cadence than positions, so it needs its own
 * high-water mark rather than sharing `last_location_poll_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->timestamp('last_telemetry_poll_at')->nullable()->after('last_location_poll_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->dropColumn('last_telemetry_poll_at');
        });
    }
};
