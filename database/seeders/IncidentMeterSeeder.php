<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class IncidentMeterSeeder extends Seeder
{
    public function run(): void
    {
        UsageMeter::query()->updateOrCreate(
            ['code' => 'incident_workflows'],
            [
                'name' => 'Incident Workflows',
                'description' => 'Incidents created (automated or manual).',
                'unit' => 'count',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );
    }
}
