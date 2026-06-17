<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Listeners\AssessPendingMediaOnEvaluationCompleted;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AssessPendingMediaOnEvaluationCompletedTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    private function evaluationForEvent(NormalizedEvent $event): AIEventEvaluation
    {
        return AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);
    }

    private function handle(AIEventEvaluation $evaluation): void
    {
        app(AssessPendingMediaOnEvaluationCompleted::class)
            ->handle(new AIEvaluationCompleted($evaluation));
    }

    public function test_assesses_media_that_persisted_before_the_evaluation_existed(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $media = EventMediaContext::factory()->count(2)->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        $evaluation = $this->evaluationForEvent($event);

        $this->handle($evaluation);

        Bus::assertDispatched(
            EvaluateEventMediaJob::class,
            fn (EvaluateEventMediaJob $job) => $job->evaluationId === $evaluation->id
                && empty(array_diff($media->pluck('id')->all(), $job->mediaContextIds))
                && count($job->mediaContextIds) === 2,
        );
    }

    public function test_skips_media_already_assessed_by_any_evaluation_version(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $media = EventMediaContext::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);

        // An earlier evaluation version already assessed this media.
        $first = $this->evaluationForEvent($event);
        AIMediaAssessment::factory()->create([
            'evaluation_id' => $first->id,
            'event_media_context_id' => $media->id,
        ]);

        // A fresh version completing must not re-bill the same asset.
        $second = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 2,
        ]);

        $this->handle($second);

        Bus::assertNotDispatched(EvaluateEventMediaJob::class);
    }

    public function test_no_op_when_event_has_no_media(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $this->handle($this->evaluationForEvent($event));

        Bus::assertNotDispatched(EvaluateEventMediaJob::class);
    }

    public function test_only_assesses_media_of_its_own_event(): void
    {
        Bus::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        $otherEvent = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        $mine = EventMediaContext::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);
        EventMediaContext::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $otherEvent->id,
        ]);

        $evaluation = $this->evaluationForEvent($event);

        $this->handle($evaluation);

        Bus::assertDispatched(
            EvaluateEventMediaJob::class,
            fn (EvaluateEventMediaJob $job) => $job->mediaContextIds === [$mine->id],
        );
    }
}
