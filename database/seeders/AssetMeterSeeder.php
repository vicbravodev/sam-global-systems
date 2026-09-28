<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class AssetMeterSeeder extends Seeder
{
    public function run(): void
    {
        $meters = [
            [
                'code' => 'monitored_assets',
                'name' => 'Monitored Assets',
                'description' => 'Number of non-inactive assets monitored/synced for the tenant.',
                'unit' => 'asset',
                'aggregation_type' => AggregationType::Max,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
            [
                'code' => 'active_cameras',
                'name' => 'Active Cameras',
                'description' => 'Daily peak of active cameras: active camera devices attached to non-inactive assets plus stand-alone camera assets without an attached camera device.',
                'unit' => 'camera',
                'aggregation_type' => AggregationType::Max,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        ];

        foreach ($meters as $meter) {
            UsageMeter::query()->updateOrCreate(['code' => $meter['code']], $meter);
        }
    }
}
