<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

/**
 * Medidor de reportes generados. `GenerateReport` lo registra con
 * `RecordUsageEvent`, que hace `firstOrFail()` sobre el código: sin esta fila
 * toda generación de reporte revienta después de escribir el archivo.
 */
class AnalyticsMeterSeeder extends Seeder
{
    public function run(): void
    {
        UsageMeter::query()->updateOrCreate(
            ['code' => 'generated_reports'],
            [
                'name' => 'Reportes generados',
                'description' => 'Analytics report executions completed (dashboard, PDF, XLSX, CSV, JSON).',
                'unit' => 'report',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );
    }
}
