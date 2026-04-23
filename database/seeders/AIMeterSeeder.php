<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class AIMeterSeeder extends Seeder
{
    public function run(): void
    {
        $meters = [
            [
                'code' => 'ai_calls',
                'name' => 'AI Evaluations',
                'description' => 'Number of AI evaluations executed.',
                'unit' => 'call',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
            [
                'code' => 'ai_tokens_in',
                'name' => 'AI Input Tokens',
                'description' => 'Input tokens consumed by the AI evaluator.',
                'unit' => 'tokens',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
            [
                'code' => 'ai_tokens_out',
                'name' => 'AI Output Tokens',
                'description' => 'Output tokens produced by the AI evaluator.',
                'unit' => 'tokens',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        ];

        foreach ($meters as $meter) {
            UsageMeter::query()->updateOrCreate(['code' => $meter['code']], $meter);
        }
    }
}
