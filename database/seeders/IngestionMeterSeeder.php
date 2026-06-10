<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class IngestionMeterSeeder extends Seeder
{
    public function run(): void
    {
        UsageMeter::query()->updateOrCreate(
            ['code' => 'ingested_events'],
            [
                'name' => 'Ingested Events',
                'description' => 'Number of provider events ingested via polling feeds.',
                'unit' => 'count',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );
    }
}
