<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Medidor de emergencias atendidas en unidades NO vigiladas: una fila por
     * unidad y día local; se factura como tracto-día + recargo. Debe existir
     * aunque nadie haya corrido los seeders (RecordUsageEvent hace firstOrFail).
     */
    public function up(): void
    {
        DB::table('usage_meters')->insertOrIgnore([
            'code' => 'unmonitored_emergency_asset_days',
            'name' => 'Emergencias en unidades no vigiladas',
            'description' => 'Días en que una unidad no vigilada envió una emergencia que SAM atendió: tracto-día + recargo.',
            'unit' => 'asset_day',
            'aggregation_type' => 'sum',
            'is_billable' => true,
            'reset_period' => 'monthly',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Sin rollback de datos: el meter puede tener eventos asociados.
    }
};
