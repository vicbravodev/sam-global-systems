<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\Context\Enums\MediaType;
use App\Infrastructure\AI\Agents\MediaInspectorAgent;
use App\Infrastructure\AI\Agents\SdkMediaAssessmentAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

class EvaluateMediaViaSdkTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrapper_parses_structured_json_response_into_assessment_output(): void
    {
        config()->set('ai.pricing', [
            'gpt-test' => ['input' => 2.0, 'output' => 8.0],
        ]);

        MediaInspectorAgent::fake([
            new TextResponse(
                json_encode([
                    'result' => 'confirms_event',
                    'confidence_score' => 0.87,
                    'summary_text' => 'Dashcam still shows the vehicle stopped on the shoulder.',
                    'extracted_signals' => ['vehicle_stopped' => true],
                ], JSON_THROW_ON_ERROR),
                new Usage(promptTokens: 500_000, completionTokens: 250_000),
                new Meta(provider: 'openai', model: 'gpt-test'),
            ),
        ]);

        $output = app(SdkMediaAssessmentAgent::class)->assess($this->makeInput());

        $this->assertSame(MediaAssessmentResult::ConfirmsEvent, $output->result);
        $this->assertSame(0.87, $output->confidenceScore);
        $this->assertSame(['vehicle_stopped' => true], $output->extractedSignals);
        $this->assertSame('laravel-ai-sdk:gpt-test', $output->modelUsed);
        $this->assertSame(500_000, $output->inputTokens);
        $this->assertSame(250_000, $output->outputTokens);
        $this->assertSame(3.0, $output->costEstimate);
        $this->assertGreaterThanOrEqual(0, $output->latencyMs);
    }

    public function test_model_without_pricing_entry_costs_zero(): void
    {
        config()->set('ai.pricing', []);

        MediaInspectorAgent::fake([
            new TextResponse(
                json_encode([
                    'result' => 'inconclusive',
                    'confidence_score' => 0.3,
                ], JSON_THROW_ON_ERROR),
                new Usage(promptTokens: 120, completionTokens: 40),
                new Meta(provider: 'openai', model: 'gpt-unpriced'),
            ),
        ]);

        $output = app(SdkMediaAssessmentAgent::class)->assess($this->makeInput());

        $this->assertSame(0.0, $output->costEstimate);
    }

    public function test_wrapper_throws_when_response_is_not_valid_json(): void
    {
        MediaInspectorAgent::fake(['this is not json']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SDK media response was not valid JSON/');

        app(SdkMediaAssessmentAgent::class)->assess($this->makeInput());
    }

    public function test_wrapper_throws_when_required_fields_missing(): void
    {
        MediaInspectorAgent::fake([
            json_encode(['only_random' => 'fields'], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing required fields/');

        app(SdkMediaAssessmentAgent::class)->assess($this->makeInput());
    }

    private function makeInput(): MediaAssessmentInput
    {
        return new MediaAssessmentInput(
            teamId: 1,
            evaluationId: 10,
            mediaContextId: 20,
            mediaType: MediaType::Image,
            assessmentType: MediaAssessmentType::ImageCheck,
            storagePath: null,
            mimeType: 'image/jpeg',
            sizeBytes: 2048,
            durationSeconds: null,
            mediaMetadata: ['camera' => 'front'],
            eventContext: ['normalized_event_id' => 5, 'classification' => 'real_event'],
        );
    }
}
