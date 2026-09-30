<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\Exceptions\MediaFileMissingException;
use App\Contracts\AI\Exceptions\MediaFileRejectedException;
use App\Domains\AI\Data\MediaAssessmentInput;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\Context\Enums\MediaType;
use App\Infrastructure\AI\Agents\MediaInspectorAgent;
use App\Infrastructure\AI\Agents\SdkMediaAssessmentAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

class EvaluateMediaViaSdkTest extends TestCase
{
    use RefreshDatabase;

    /** Minimal JPEG header: enough for the magic-byte validation. */
    private const string JPEG_BYTES = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00fake-jpeg-body";

    private const string DEFAULT_PATH = 'media/still.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
        Storage::disk('rustfs')->put(self::DEFAULT_PATH, self::JPEG_BYTES);
    }

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
                new TextUsage(inputTokens: 500_000, outputTokens: 250_000),
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
                new TextUsage(inputTokens: 120, outputTokens: 40),
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

    /**
     * Regression: event media is persisted on the `rustfs` disk (see
     * RustFsObjectStorage), never on `filesystems.default`. The agent must
     * look up the attachment there or it silently prompts with zero
     * attachments and the model reports the media as unavailable.
     */
    public function test_attachment_is_built_from_the_rustfs_disk_not_the_default_disk(): void
    {
        Storage::fake('rustfs');
        Storage::fake('local');
        Storage::disk('rustfs')->put('media/panic-still.jpg', self::JPEG_BYTES);

        MediaInspectorAgent::fake([
            new TextResponse(
                json_encode([
                    'result' => 'confirms_event',
                    'confidence_score' => 0.9,
                    'summary_text' => 'Se observa al conductor en la cabina.',
                    'extracted_signals' => [],
                ], JSON_THROW_ON_ERROR),
                new TextUsage(inputTokens: 100, outputTokens: 50),
                new Meta(provider: 'openai', model: 'gpt-test'),
            ),
        ]);

        app(SdkMediaAssessmentAgent::class)->assess($this->makeInput(storagePath: 'media/panic-still.jpg'));

        MediaInspectorAgent::assertPrompted(fn ($prompt) => $prompt->attachments->isNotEmpty());
    }

    public function test_inspector_declares_structured_output_schema(): void
    {
        $agent = new MediaInspectorAgent;

        $this->assertInstanceOf(HasStructuredOutput::class, $agent);
        $this->assertSame(
            ['result', 'confidence_score', 'summary_text', 'extracted_signals'],
            array_keys($agent->schema(new JsonSchemaTypeFactory)),
        );
    }

    public function test_wrapper_consumes_native_structured_response_and_flattens_signals(): void
    {
        MediaInspectorAgent::fake([[
            'result' => 'confirms_event',
            'confidence_score' => 0.9,
            'summary_text' => 'Se observa a una persona desconocida junto al conductor.',
            'extracted_signals' => [
                'persons_visible_count' => 2,
                'passenger_detected' => true,
                'driver_visible' => true,
                'visible_threat' => null,
                'cabin_appears_normal' => false,
                'vehicle_moving' => false,
                'additional_signals' => [['name' => 'hands_raised', 'value' => 'true']],
            ],
        ]]);

        $output = app(SdkMediaAssessmentAgent::class)->assess($this->makeInput());

        $this->assertSame(MediaAssessmentResult::ConfirmsEvent, $output->result);
        $this->assertSame(2, $output->extractedSignals['persons_visible_count']);
        $this->assertSame('true', $output->extractedSignals['hands_raised']);
        $this->assertArrayNotHasKey('additional_signals', $output->extractedSignals);
    }

    public function test_wrapper_tolerates_fences_percentage_confidence_and_unknown_result(): void
    {
        MediaInspectorAgent::fake([
            "```json\n".json_encode([
                'result' => 'threat_detected',
                'confidence_score' => 72,
                'summary_text' => 'Imagen nocturna borrosa.',
                'extracted_signals' => [],
            ], JSON_THROW_ON_ERROR)."\n```",
        ]);

        $output = app(SdkMediaAssessmentAgent::class)->assess($this->makeInput());

        $this->assertSame(MediaAssessmentResult::Inconclusive, $output->result);
        $this->assertSame(0.72, $output->confidenceScore);
    }

    public function test_missing_file_throws_without_calling_the_model(): void
    {
        MediaInspectorAgent::fake();

        try {
            app(SdkMediaAssessmentAgent::class)->assess($this->makeInput(storagePath: 'media/does-not-exist.jpg'));
            $this->fail('Expected MediaFileMissingException');
        } catch (MediaFileMissingException) {
            // expected
        }

        MediaInspectorAgent::assertNeverPrompted();
    }

    public function test_non_image_bytes_are_rejected_without_calling_the_model(): void
    {
        MediaInspectorAgent::fake();
        Storage::disk('rustfs')->put('media/error-page.jpg', '<html>403 Forbidden</html>');

        try {
            app(SdkMediaAssessmentAgent::class)->assess($this->makeInput(storagePath: 'media/error-page.jpg'));
            $this->fail('Expected MediaFileRejectedException');
        } catch (MediaFileRejectedException $exception) {
            $this->assertSame('invalid_image', $exception->reason);
        }

        MediaInspectorAgent::assertNeverPrompted();
    }

    public function test_oversize_image_is_rejected_without_calling_the_model(): void
    {
        config()->set('ai.media.max_image_bytes', 10);
        MediaInspectorAgent::fake();

        try {
            app(SdkMediaAssessmentAgent::class)->assess($this->makeInput());
            $this->fail('Expected MediaFileRejectedException');
        } catch (MediaFileRejectedException $exception) {
            $this->assertSame('oversize', $exception->reason);
        }

        MediaInspectorAgent::assertNeverPrompted();
    }

    public function test_image_is_sent_with_the_mime_detected_from_its_bytes(): void
    {
        Storage::disk('rustfs')->put('media/still-octet.bin', "\x89PNG\r\n\x1A\nfake-png");

        MediaInspectorAgent::fake([
            json_encode(['result' => 'inconclusive', 'confidence_score' => 0.4], JSON_THROW_ON_ERROR),
        ]);

        app(SdkMediaAssessmentAgent::class)->assess($this->makeInput(storagePath: 'media/still-octet.bin'));

        MediaInspectorAgent::assertPrompted(fn ($prompt) => $prompt->attachments->first()?->mimeType() === 'image/png');
    }

    private function makeInput(?string $storagePath = self::DEFAULT_PATH): MediaAssessmentInput
    {
        return new MediaAssessmentInput(
            teamId: 1,
            evaluationId: 10,
            mediaContextId: 20,
            mediaType: MediaType::Image,
            assessmentType: MediaAssessmentType::ImageCheck,
            storagePath: $storagePath,
            mimeType: 'image/jpeg',
            sizeBytes: 2048,
            durationSeconds: null,
            mediaMetadata: ['camera' => 'front'],
            eventContext: ['normalized_event_id' => 5, 'classification' => 'real_event'],
        );
    }
}
