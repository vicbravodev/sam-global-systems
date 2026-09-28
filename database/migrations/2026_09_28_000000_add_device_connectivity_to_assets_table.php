<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real device connectivity, reported by the provider's gateway endpoint
     * (Samsara `GET /gateways` → `connectionStatus`). The offline watchdog
     * keys off `device_last_connected_at`, not `last_seen_at`: the latter is
     * the last GPS fix, which freezes while a vehicle is parked even though
     * its gateway is still online, and flooded `device_offline` events.
     *
     * `device_connectivity_polled_at` is when WE last got a connectivity
     * reading for the asset; the watchdog ignores assets whose reading is
     * stale so a provider/API outage never looks like a fleet-wide outage.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('device_last_connected_at')->nullable();
            $table->string('device_health_status')->nullable();
            $table->timestamp('device_connectivity_polled_at')->nullable();

            $table->index(['team_id', 'device_last_connected_at']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'device_last_connected_at']);
            $table->dropColumn(['device_last_connected_at', 'device_health_status', 'device_connectivity_polled_at']);
        });
    }
};
