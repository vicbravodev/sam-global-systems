<?php

namespace Database\Factories\Domains\Analytics;

use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Models\KpiRecord;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KpiRecord>
 */
class KpiRecordFactory extends Factory
{
    protected $model = KpiRecord::class;

    public function definition(): array
    {
        $start = now()->subDay()->startOfDay();

        return [
            'team_id' => Team::factory(),
            'kpi_code' => 'incidents_total',
            'period_type' => PeriodType::Daily,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfDay(),
            'dimension_type' => null,
            'dimension_reference' => null,
            'value' => 42.0,
            'unit' => 'count',
            'metadata_json' => null,
            'calculated_at' => now(),
        ];
    }
}
