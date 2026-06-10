<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class ContextMeterSeeder extends Seeder
{
    public function run(): void
    {
        UsageMeter::query()->updateOrCreate(
            ['code' => 'media_requests'],
            [
                'name' => 'Media Requests',
                'description' => 'Number of deferred camera media retrievals requested from providers.',
                'unit' => 'count',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );
    }
}
