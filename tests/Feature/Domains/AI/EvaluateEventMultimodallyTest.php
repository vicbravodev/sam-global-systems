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
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class EvaluateEventMultimodallyTest extends TestCase
{
    use AssertsSystemLog;
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
        $image = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Image,
        ]);

        $assessments = app(EvaluateEventMultimodally::class)->execute(
            $evaluation->fresh(),
            collect([$snapshot, $image]),
        );

        $this->assertCount(2, $assessments);
        $this->assertSame(2, AIMediaAssessment::where('evaluation_id', $evaluation->id)->count());

        $snapshotAssessment = AIMediaAssessment::where('event_media_context_id', $snapshot->id)->first();
        $this->assertSame(MediaAssessmentType::VisualValidation, $snapshotAssessment->assessment_type);

        $imageAssessment = AIMediaAssessment::where('event_media_context_id', $image->id)->first();
        $this->assertSame(MediaAssessmentType::VisualValidation, $imageAssessment->assessment_type);

        $this->assertSame(EvaluationMode::Multimodal, $evaluation->fresh()->evaluation_mode);
        $this->assertSame(2, AIInferenceLog::where('evaluation_id', $evaluation->id)->value('media_assets_count'));

        $assessed = $this->assertSystemLogged('ai.media.assessed', fn (array $c): bool => $c['input']['event_media_context_id'] === $snapshot->id);
        $this->assertSame($evaluation->id, $assessed['input']['evaluation_id']);
        $this->assertSame('visual_validation', $assessed['input']['assessment_type']);
        $this->assertSame('snapshot', $assessed['input']['media_type']);
        $this->assertSame($snapshotAssessment->id, $assessed['result']['assessment_id']);
        $this->assertSame('confirms_event', $assessed['result']['assessment_result']);
        $this->assertSame(250, $assessed['result']['input_tokens']);
        $this->assertSame(80, $assessed['result']['output_tokens']);
        $this->assertSame('null-media-agent:1.0', $assessed['result']['model']);
        $this->assertSame(0.8, $assessed['result']['confidence']);
        $this->assertFalse($assessed['result']['visible_threat']);
        $this->assertSame(8, $assessed['calc']['max_images_per_event']);
        $this->assertSame(8, $assessed['calc']['remaining_slots_before']);
        $this->assertCount(2, $this->systemLogEntries('ai.media.assessed'));

        $batch = $this->assertSystemLogged('ai.media.batch_completed');
        $this->assertSame($evaluation->id, $batch['input']['evaluation_id']);
        $this->assertSame(2, $batch['calc']['received_count']);
        $this->assertSame(2, $batch['calc']['image_count']);
        $this->assertSame(8, $batch['calc']['remaining_slots_at_start']);
        $this->assertSame(2, $batch['result']['created_count']);
        $this->assertSame(0, $batch['result']['reused_count']);
        $this->assertFalse($batch['result']['retry_pending']);
        $this->assertSame('ai_text', $batch['result']['mode_before']);
        $this->assertSame('multimodal', $batch['result']['mode_after']);

        $this->assertNoSensitiveDataLogged();
        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString($snapshotAssessment->summary_text, $json);
        $this->assertStringNotContainsString((string) $snapshot->storage_path, $json);
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
        $firstRun = count($this->systemLogEntries());
        $action->execute($evaluation->fresh(), collect([$media]));
        $secondRun = array_slice($this->systemLogEntries(), $firstRun);
        $codes = array_column($secondRun, 'code');

        $this->assertNotContains('ai.media.assessed', $codes);
        $reused = $secondRun[array_search('ai.media.reused', $codes, true)];
        $this->assertSame('debug', $reused['level']);
        $this->assertSame('already_assessed_same_evaluation', $reused['context']['reason']);
        $this->assertSame($media->id, $reused['context']['input']['event_media_context_id']);
        $this->assertSame('confirms_event', $reused['context']['result']['assessment_result']);

        $batch = $secondRun[array_search('ai.media.batch_completed', $codes, true)]['context'];
        $this->assertSame(0, $batch['result']['created_count']);
        $this->assertSame(1, $batch['result']['reused_count']);
        $this->assertNoSensitiveDataLogged();

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
            'summary_text' => 'El análisis visual no estuvo disponible para esta media.',
        ]);

        $assessment = AIMediaAssessment::query()->where('evaluation_id', $evaluation->id)->firstOrFail();
        $this->assertSame(['error_class' => 'RuntimeException'], $assessment->extracted_signals_json);
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

        $ctx = $this->assertSystemLogged('ai.media.batch_skipped');
        $this->assertSame('no_media', $ctx['reason']);
        $this->assertSame($evaluation->id, $ctx['input']['evaluation_id']);
        $this->assertSame('debug', $this->systemLogEntries('ai.media.batch_skipped')[0]['level']);
        $this->assertSystemNotLogged('ai.media.batch_completed');
        $this->assertNoSensitiveDataLogged();
    }
}
