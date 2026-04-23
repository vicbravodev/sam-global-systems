<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class AiUsageMeterSeeder extends Seeder
{
    public function run(): void
    {
        $meters = [
            [
                'code' => 'ai_calls',
                'name' => 'AI Evaluation Calls',
                'description' => 'One unit per AI event evaluation dispatched by EvaluateEventJob.',
                'unit' => 'call',
                'aggregation_type' => AggregationType::Sum->value,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly->value,
            ],
            [
                'code' => 'ai_tokens_in',
                'name' => 'AI Input Tokens',
                'description' => 'Input tokens consumed by AI SDK calls (populated from SDK AgentStreamed events).',
                'unit' => 'tokens',
                'aggregation_type' => AggregationType::Sum->value,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly->value,
            ],
            [
                'code' => 'ai_tokens_out',
                'name' => 'AI Output Tokens',
                'description' => 'Output tokens produced by AI SDK calls (populated from SDK AgentStreamed events).',
                'unit' => 'tokens',
                'aggregation_type' => AggregationType::Sum->value,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly->value,
            ],
        ];

        foreach ($meters as $meter) {
            UsageMeter::query()->updateOrCreate(
                ['code' => $meter['code']],
                $meter,
            );
        }
    }
}
