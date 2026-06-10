<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class AutomationMeterSeeder extends Seeder
{
    public function run(): void
    {
        UsageMeter::query()->updateOrCreate(
            ['code' => 'incident_workflows'],
            [
                'name' => 'Incident Workflows',
                'description' => 'Automation workflow executions triggered by incidents or decisions.',
                'unit' => 'workflow',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );

        UsageMeter::query()->updateOrCreate(
            ['code' => 'automation_actions'],
            [
                'name' => 'Automation Actions',
                'description' => 'Individual automation actions executed successfully (notifications, incident ops, webhooks).',
                'unit' => 'action',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );
    }
}
