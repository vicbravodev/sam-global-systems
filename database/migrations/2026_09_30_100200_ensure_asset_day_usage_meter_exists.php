<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Medidor de tracto-día: cada noche se registra cuántos activos estuvieron
     * vigilados; la suma del mes es la base de la factura. Debe existir aunque
     * nadie haya corrido los seeders (RecordUsageEvent hace firstOrFail).
     */
    public function up(): void
    {
        DB::table('usage_meters')->insertOrIgnore([
            'code' => 'monitored_asset_days',
            'name' => 'Tracto-días vigilados',
            'description' => 'Suma diaria de activos vigilados: base del cobro por tracto-día.',
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
