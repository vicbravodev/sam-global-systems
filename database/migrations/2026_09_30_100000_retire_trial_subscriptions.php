<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sin periodo de prueba (decisión de producto 2026-09-28): SAM factura por
     * tracto-día desde el alta. Las suscripciones que seguían en `trialing`
     * pasan a `active`; la columna `trial_ends_at` se conserva (migraciones
     * additive-only) pero el código ya no la lee ni la escribe.
     */
    public function up(): void
    {
        DB::table('team_subscriptions')
            ->where('status', 'trialing')
            ->update(['status' => 'active', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Sin rollback de datos: no se sabe qué filas eran trial.
    }
};
