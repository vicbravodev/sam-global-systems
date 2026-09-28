<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vigilancia por activo (decisión 2026-09-28): el sync descubre TODA la
     * flota del proveedor, pero SAM sólo vigila (sondea, normaliza, evalúa,
     * alerta y factura) los activos en `monitored`. Los recién descubiertos
     * quedan en `pending` hasta que el cliente los enciende; `excluded` es una
     * baja explícita. Los activos existentes ya estaban vigilados: default
     * `monitored`.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('monitoring_state')->default('monitored')->after('status');
            $table->timestamp('monitoring_changed_at')->nullable()->after('monitoring_state');

            $table->index(['team_id', 'monitoring_state']);
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'monitoring_state']);
            $table->dropColumn(['monitoring_state', 'monitoring_changed_at']);
        });
    }
};
