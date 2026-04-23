<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\NullImplementations\NullAiProviderAdapter;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use Tests\TestCase;

class NullAiProviderAdapterTest extends TestCase
{
    public function test_classifies_high_severity_as_real_event(): void
    {
        $input = [
            'event' => ['severity' => 'high'],
            'context' => ['signals' => [], 'profile' => ['priority_score' => 0.7]],
        ];

        $result = (new NullAiProviderAdapter)->evaluate($input);

        $this->assertSame(EventClassification::RealEvent, $result->classification);
        $this->assertTrue($result->isRealEvent);
        $this->assertSame(EvaluationMode::RulesOnly, $result->mode);
        $this->assertSame(NullAiProviderAdapter::MODEL_IDENTIFIER, $result->modelUsed);
    }

    public function test_classifies_low_severity_with_no_recurrence_as_false_positive(): void
    {
        $input = [
            'event' => ['severity' => 'low'],
            'context' => ['signals' => [], 'profile' => ['priority_score' => 0.1, 'recurrence_score' => 0.0]],
        ];

        $result = (new NullAiProviderAdapter)->evaluate($input);

        $this->assertSame(EventClassification::FalsePositive, $result->classification);
        $this->assertFalse($result->isRealEvent);
    }

    public function test_classifies_high_recurrence_low_severity_as_noise(): void
    {
        $input = [
            'event' => ['severity' => 'low'],
            'context' => [
                'signals' => [
                    'recent_same_type_count' => 6,
                    'recent_high_severity_count' => 0,
                ],
                'profile' => ['priority_score' => 0.3, 'recurrence_score' => 0.1],
            ],
        ];

        $result = (new NullAiProviderAdapter)->evaluate($input);

        $this->assertSame(EventClassification::Noise, $result->classification);
    }

    public function test_describe_returns_model_identifier(): void
    {
        $this->assertSame(NullAiProviderAdapter::MODEL_IDENTIFIER, (new NullAiProviderAdapter)->describe());
    }

    public function test_result_tokens_and_cost_are_zero(): void
    {
        $result = (new NullAiProviderAdapter)->evaluate(['event' => ['severity' => 'medium'], 'context' => ['signals' => []]]);

        $this->assertSame(0, $result->tokensUsed);
        $this->assertSame(0.0, $result->costEstimate);
    }
}
