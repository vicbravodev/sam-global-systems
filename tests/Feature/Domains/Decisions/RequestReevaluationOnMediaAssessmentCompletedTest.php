<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Decisions\Listeners\RequestReevaluationOnMediaAssessmentCompleted;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Roadmap B8: deferred media must re-open the decision pipeline — unless the
 * decision never ran (inline media), the footage was already scored under a
 * previous evaluation version, or the incident is terminally closed.
 */
class RequestReevaluationOnMediaAssessmentCompletedTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ReevaluateEventJob::class]);

        $this->team = User::factory()->create()->currentTeam;
    }

    /**
     * @return array{0: NormalizedEvent, 1: AIEventEvaluation, 2: AIMediaAssessment}
     */
    private function makeAssessedEvaluation(int $evaluationVersion = 1): array
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => $evaluationVersion,
        ]);

        $media = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        $assessment = AIMediaAssessment::factory()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
        ]);

        return [$event, $evaluation, $assessment];
    }

    private function assessNewMedia(NormalizedEvent $event, AIEventEvaluation $evaluation): AIMediaAssessment
    {
        $media = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        return AIMediaAssessment::factory()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
        ]);
    }

    private function handle(AIEventEvaluation $evaluation, AIMediaAssessment $assessment): void
    {
        (new RequestReevaluationOnMediaAssessmentCompleted)->handle(
            new MediaAssessmentCompleted($evaluation, collect([$assessment])),
        );
    }

    public function test_dispatches_reevaluation_when_decision_already_ran(): void
    {
        [$event, $evaluation, $assessment] = $this->makeAssessedEvaluation();

        Decision::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'ai_evaluation_id' => $evaluation->id,
        ]);

        Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        $this->handle($evaluation, $assessment);

        Bus::assertDispatched(
            ReevaluateEventJob::class,
            fn (ReevaluateEventJob $job) => $job->normalizedEventId === $event->id
                && $job->triggerType === ReevaluationTrigger::MediaArrived->value
                && $job->triggerReferenceId === $assessment->id
        );
    }

    public function test_burst_of_assessments_coalesces_into_one_delayed_reevaluation(): void
    {
        config(['ai.reevaluation.media_debounce_seconds' => 45]);

        [$event, $evaluation, $firstAssessment] = $this->makeAssessedEvaluation();

        Decision::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'ai_evaluation_id' => $evaluation->id,
        ]);

        Incident::factory()->open()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        // A panic-style burst: every clip lands as its own assessment and
        // fires its own MediaAssessmentCompleted event.
        $this->handle($evaluation, $firstAssessment);
        $this->handle($evaluation, $this->assessNewMedia($event, $evaluation));
        $this->handle($evaluation, $this->assessNewMedia($event, $evaluation));

        Bus::assertDispatchedTimes(ReevaluateEventJob::class, 1);

        Bus::assertDispatched(
            ReevaluateEventJob::class,
            fn (ReevaluateEventJob $job) => $job->delay === 45
                && $job->normalizedEventId === $event->id
                && $job->triggerType === ReevaluationTrigger::MediaArrived->value
        );
    }

    public function test_distinct_events_keep_independent_debounce_windows(): void
    {
        foreach (range(1, 2) as $i) {
            [$event, $evaluation, $assessment] = $this->makeAssessedEvaluation();

            Decision::factory()->create([
                'team_id' => $this->team->id,
                'normalized_event_id' => $event->id,
                'ai_evaluation_id' => $evaluation->id,
            ]);

            Incident::factory()->open()->create([
                'team_id' => $this->team->id,
                'related_event_id' => $event->id,
            ]);

            $this->handle($evaluation, $assessment);
        }

        Bus::assertDispatchedTimes(ReevaluateEventJob::class, 2);
    }

    public function test_no_ops_when_decision_has_not_run_yet(): void
    {
        [, $evaluation, $assessment] = $this->makeAssessedEvaluation();

        $this->handle($evaluation, $assessment);

        Bus::assertNotDispatched(ReevaluateEventJob::class);
    }

    public function test_no_ops_when_incident_is_terminal(): void
    {
        [$event, $evaluation, $assessment] = $this->makeAssessedEvaluation();

        Decision::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'ai_evaluation_id' => $evaluation->id,
        ]);

        Incident::factory()->closed()->create([
            'team_id' => $this->team->id,
            'related_event_id' => $event->id,
        ]);

        $this->handle($evaluation, $assessment);

        Bus::assertNotDispatched(ReevaluateEventJob::class);
    }

    public function test_no_ops_when_media_was_already_assessed_under_previous_evaluation(): void
    {
        [$event, $previousEvaluation, $previousAssessment] = $this->makeAssessedEvaluation();

        $currentEvaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 2,
        ]);

        Decision::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'ai_evaluation_id' => $currentEvaluation->id,
        ]);

        // The same footage re-scored under the new evaluation version must not
        // re-open the pipeline again (loop guard).
        $reAssessment = AIMediaAssessment::factory()->create([
            'evaluation_id' => $currentEvaluation->id,
            'event_media_context_id' => $previousAssessment->event_media_context_id,
        ]);

        $this->handle($currentEvaluation, $reAssessment);

        Bus::assertNotDispatched(ReevaluateEventJob::class);

        // Sanity: the previous evaluation's assessment still exists untouched.
        $this->assertSame(2, AIMediaAssessment::query()->count());
        $this->assertNotNull($previousEvaluation->fresh());
    }
}
