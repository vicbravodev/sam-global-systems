<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\Exceptions\MediaFileMissingException;
use App\Contracts\AI\Exceptions\MediaFileRejectedException;
use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Support\ImageSignature;
use App\Domains\AI\Support\RetryableAIError;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\Team;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class VisionRetryAndValidationTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private NullMediaAssessmentAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);

        $agent = app(MediaAssessmentAgent::class);
        $this->assertInstanceOf(NullMediaAssessmentAgent::class, $agent);
        $this->agent = $agent;
    }

    public function test_missing_file_is_skipped_without_persisting_a_row(): void
    {
        Event::fake([MediaAssessmentCompleted::class]);
        [$evaluation, $media] = $this->makeEvaluationWithMedia(1);
        $this->agent->failWith = MediaFileMissingException::forPath('x.jpg');

        $result = app(EvaluateEventMultimodally::class)->execute($evaluation, $media);

        $this->assertCount(0, $result);
        $this->assertSame(0, AIMediaAssessment::query()->count());
        $this->assertSame(EvaluationMode::AiText, $evaluation->fresh()->evaluation_mode);
        Event::assertNotDispatched(MediaAssessmentCompleted::class);

        $this->assertSame('file_missing', $this->assertSystemLogged('ai.media.assessment_skipped')['reason']);
        $batch = $this->assertSystemLogged('ai.media.batch_completed');
        $this->assertSame(0, $batch['result']['created_count']);
        $this->assertSame('ai_text', $batch['result']['mode_after']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_rejected_image_is_recorded_as_low_quality_without_usage(): void
    {
        Event::fake([UsageRecorded::class]);
        [$evaluation, $media] = $this->makeEvaluationWithMedia(1);
        $this->agent->failWith = new MediaFileRejectedException('invalid_image', 'No es una imagen.');

        app(EvaluateEventMultimodally::class)->execute($evaluation, $media);

        $assessment = AIMediaAssessment::query()->sole();
        $this->assertSame(MediaAssessmentResult::LowQuality, $assessment->result);
        $this->assertSame('media-validator', $assessment->model_used);
        $this->assertSame('invalid_image', $assessment->extracted_signals_json['rejected_reason']);
        Event::assertNotDispatched(UsageRecorded::class);

        $this->assertSame('rejected_before_model', $this->assertSystemLogged('ai.media.assessment_rejected')['reason']);
        $this->assertSystemNotLogged('ai.media.assessed');
        $this->assertSame(1, $this->assertSystemLogged('ai.media.batch_completed')['result']['created_count']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString($assessment->summary_text, json_encode($this->systemLogEntries()));
    }

    public function test_retryable_error_is_rethrown_before_the_final_attempt(): void
    {
        [$evaluation, $media] = $this->makeEvaluationWithMedia(1);
        $this->agent->failWith = new RuntimeException(
            'Laravel AI SDK media invocation failed',
            previous: RateLimitedException::forProvider('openai'),
        );

        try {
            app(EvaluateEventMultimodally::class)->execute($evaluation, $media, finalAttempt: false);
            $this->fail('Expected the transient error to be rethrown');
        } catch (RuntimeException $exception) {
            $this->assertInstanceOf(RateLimitedException::class, $exception->getPrevious());
        }

        $this->assertSame(0, AIMediaAssessment::query()->count());

        $this->assertSame('transient_failure', $this->assertSystemLogged('ai.media.assessment_retry')['reason']);
        $batch = $this->assertSystemLogged('ai.media.batch_completed');
        $this->assertTrue($batch['result']['retry_pending']);
        $this->assertSame(0, $batch['result']['created_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_retryable_error_on_final_attempt_is_recorded_as_unavailable(): void
    {
        [$evaluation, $media] = $this->makeEvaluationWithMedia(1);
        $this->agent->failWith = new ConnectionException('cURL error 28: Operation timed out');

        app(EvaluateEventMultimodally::class)->execute($evaluation, $media, finalAttempt: true);

        $this->assertSame(MediaAssessmentResult::Unavailable, AIMediaAssessment::query()->sole()->result);

        $this->assertSame('agent_error', $this->assertSystemLogged('ai.media.assessment_unavailable')['reason']);
        $this->assertFalse($this->assertSystemLogged('ai.media.batch_completed')['result']['retry_pending']);
    }

    public function test_non_retryable_error_is_recorded_as_unavailable_even_with_retries_left(): void
    {
        [$evaluation, $media] = $this->makeEvaluationWithMedia(1);
        $this->agent->failWith = new RuntimeException('SDK media response was not valid JSON');

        app(EvaluateEventMultimodally::class)->execute($evaluation, $media, finalAttempt: false);

        $this->assertSame(MediaAssessmentResult::Unavailable, AIMediaAssessment::query()->sole()->result);
    }

    public function test_job_on_its_last_attempt_records_transient_failure_as_unavailable(): void
    {
        [$evaluation, $media] = $this->makeEvaluationWithMedia(1);
        $this->agent->failWith = RateLimitedException::forProvider('openai');

        $job = new EvaluateEventMediaJob($evaluation->id, $media->pluck('id')->all());
        $job->tries = 1;
        $job->handle(app(EvaluateEventMultimodally::class));

        $this->assertSame(MediaAssessmentResult::Unavailable, AIMediaAssessment::query()->sole()->result);
    }

    public function test_images_per_event_are_capped(): void
    {
        config()->set('ai.media.max_images_per_event', 2);
        [$evaluation, $media] = $this->makeEvaluationWithMedia(3);

        app(EvaluateEventMultimodally::class)->execute($evaluation, $media);

        $this->assertSame(2, AIMediaAssessment::query()->count());
        $this->assertCount(2, $this->agent->receivedInputs);

        $this->assertSame('image_cap_reached', $this->assertSystemLogged('ai.media.assessment_skipped')['reason']);
        $assessed = $this->systemLogEntries('ai.media.assessed');
        $this->assertSame([2, 1], array_map(fn (array $e): int => $e['context']['calc']['remaining_slots_before'], $assessed));

        // Otra versión de evaluación del mismo evento tampoco pasa del tope.
        $second = AIEventEvaluation::factory()->create([
            'team_id' => $evaluation->team_id,
            'normalized_event_id' => $evaluation->normalized_event_id,
            'evaluation_version' => 2,
        ]);

        app(EvaluateEventMultimodally::class)->execute($second, $media->slice(2)->values());

        $this->assertSame(2, AIMediaAssessment::query()->count());
    }

    public function test_image_signature_detection(): void
    {
        $this->assertSame('image/jpeg', ImageSignature::detect("\xFF\xD8\xFF\xE0rest"));
        $this->assertSame('image/png', ImageSignature::detect("\x89PNG\r\n\x1A\nrest"));
        $this->assertSame('image/gif', ImageSignature::detect('GIF89arest'));
        $this->assertSame('image/webp', ImageSignature::detect('RIFF1234WEBPVP8 '));
        $this->assertNull(ImageSignature::detect('<html>'));
        $this->assertNull(ImageSignature::detect(''));
    }

    public function test_retryable_error_classification(): void
    {
        $this->assertTrue(RetryableAIError::isRetryable(RateLimitedException::forProvider('openai')));
        $this->assertTrue(RetryableAIError::isRetryable(new ConnectionException('Connection refused')));
        $this->assertTrue(RetryableAIError::isRetryable(new RuntimeException('wrapped', previous: new RuntimeException('Operation timed out after 30000 milliseconds'))));
        $this->assertFalse(RetryableAIError::isRetryable(new RuntimeException('SDK media response was not valid JSON')));
    }

    /**
     * @return array{0: AIEventEvaluation, 1: Collection<int, EventMediaContext>}
     */
    private function makeEvaluationWithMedia(int $count): array
    {
        $team = Team::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_mode' => EvaluationMode::AiText,
        ]);

        $media = EventMediaContext::factory()->count($count)->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Snapshot,
        ]);

        return [$evaluation, $media];
    }
}
