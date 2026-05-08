<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Enums\MediaAssessmentType;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EvaluateEventMultimodallyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_multimodal_pipeline_persists_assessment_per_media(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_mode' => EvaluationMode::AiText,
        ]);

        AIInferenceLog::factory()->create([
            'evaluation_id' => $evaluation->id,
            'media_assets_count' => 0,
        ]);

        $snapshot = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Snapshot,
        ]);
        $clip = EventMediaContext::factory()->videoClip()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        $assessments = app(EvaluateEventMultimodally::class)->execute(
            $evaluation->fresh(),
            collect([$snapshot, $clip]),
        );

        $this->assertCount(2, $assessments);
        $this->assertSame(2, AIMediaAssessment::where('evaluation_id', $evaluation->id)->count());

        $snapshotAssessment = AIMediaAssessment::where('event_media_context_id', $snapshot->id)->first();
        $this->assertSame(MediaAssessmentType::VisualValidation, $snapshotAssessment->assessment_type);

        $clipAssessment = AIMediaAssessment::where('event_media_context_id', $clip->id)->first();
        $this->assertSame(MediaAssessmentType::ClipReview, $clipAssessment->assessment_type);

        $this->assertSame(EvaluationMode::Multimodal, $evaluation->fresh()->evaluation_mode);
        $this->assertSame(2, AIInferenceLog::where('evaluation_id', $evaluation->id)->value('media_assets_count'));
    }

    public function test_multimodal_pipeline_is_idempotent_per_evaluation_and_media(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);
        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        $action = app(EvaluateEventMultimodally::class);

        $action->execute($evaluation, collect([$media]));
        $action->execute($evaluation->fresh(), collect([$media]));

        $this->assertSame(1, AIMediaAssessment::query()
            ->where('evaluation_id', $evaluation->id)
            ->where('event_media_context_id', $media->id)
            ->count());
    }

    public function test_multimodal_pipeline_records_usage_events_per_media(): void
    {
        Event::fake([UsageRecorded::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);
        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        app(EvaluateEventMultimodally::class)->execute($evaluation, collect([$media]));

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'ai_calls'
            && str_starts_with($ev->eventKey, 'ai_call:media:'));
        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'ai_tokens_in'
            && str_starts_with($ev->eventKey, 'ai_tokens_in:media:'));
        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'ai_tokens_out'
            && str_starts_with($ev->eventKey, 'ai_tokens_out:media:'));
    }

    public function test_agent_failure_records_unavailable_assessment_without_aborting(): void
    {
        $agent = app(MediaAssessmentAgent::class);
        $this->assertInstanceOf(NullMediaAssessmentAgent::class, $agent);
        $agent->shouldFail = true;

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);
        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        app(EvaluateEventMultimodally::class)->execute($evaluation, collect([$media]));

        $this->assertDatabaseHas('ai_media_assessments', [
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
            'result' => MediaAssessmentResult::Unavailable->value,
            'model_used' => 'media-agent:error',
        ]);
    }

    public function test_empty_media_collection_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_mode' => EvaluationMode::AiText,
        ]);

        $assessments = app(EvaluateEventMultimodally::class)->execute($evaluation, collect());

        $this->assertCount(0, $assessments);
        $this->assertSame(0, AIMediaAssessment::where('evaluation_id', $evaluation->id)->count());
        $this->assertSame(EvaluationMode::AiText, $evaluation->fresh()->evaluation_mode);
    }
}
