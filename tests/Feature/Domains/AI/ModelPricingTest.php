<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Support\ModelPricing;
use Tests\TestCase;

class ModelPricingTest extends TestCase
{
    public function test_estimates_cost_for_exact_model_match(): void
    {
        config()->set('ai.pricing', [
            'gpt-5.4' => ['input' => 2.0, 'output' => 8.0],
        ]);

        $cost = app(ModelPricing::class)->estimateCost('gpt-5.4', 500_000, 250_000);

        $this->assertSame(3.0, $cost);
    }

    public function test_falls_back_to_longest_configured_prefix_for_versioned_model_ids(): void
    {
        config()->set('ai.pricing', [
            'gpt-5.4' => ['input' => 2.0, 'output' => 8.0],
            'gpt-5.4-nano' => ['input' => 0.5, 'output' => 1.0],
        ]);

        $pricing = app(ModelPricing::class);

        $this->assertSame(3.0, $pricing->estimateCost('gpt-5.4-2026-05-13', 500_000, 250_000));
        $this->assertSame(0.5, $pricing->estimateCost('gpt-5.4-nano-2026-05-13', 500_000, 250_000));
    }

    public function test_matches_model_ids_case_insensitively(): void
    {
        config()->set('ai.pricing', [
            'gpt-5.4' => ['input' => 2.0, 'output' => 8.0],
        ]);

        $cost = app(ModelPricing::class)->estimateCost('GPT-5.4', 500_000, 250_000);

        $this->assertSame(3.0, $cost);
    }

    public function test_unknown_or_missing_models_cost_zero(): void
    {
        config()->set('ai.pricing', [
            'gpt-5.4' => ['input' => 2.0, 'output' => 8.0],
        ]);

        $pricing = app(ModelPricing::class);

        $this->assertSame(0.0, $pricing->estimateCost('claude-sonnet-4-6', 500_000, 250_000));
        $this->assertSame(0.0, $pricing->estimateCost(null, 500_000, 250_000));
        $this->assertSame(0.0, $pricing->estimateCost('', 500_000, 250_000));
    }

    public function test_empty_pricing_table_costs_zero(): void
    {
        config()->set('ai.pricing', []);

        $this->assertSame(0.0, app(ModelPricing::class)->estimateCost('gpt-5.4', 500_000, 250_000));
    }
}
