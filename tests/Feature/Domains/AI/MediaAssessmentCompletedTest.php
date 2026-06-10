<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MediaAssessmentCompletedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    /**
     * @return array{0: AIEventEvaluation, 1: EventMediaContext}
     */
    private function makeEvaluationWithMedia(): array
    {
        $team = User::factory()->create()->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);
        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        return [$evaluation, $media];
    }

    public function test_event_dispatched_with_newly_created_assessments(): void
    {
        Event::fake([MediaAssessmentCompleted::class]);

        [$evaluation, $media] = $this->makeEvaluationWithMedia();

        app(EvaluateEventMultimodally::class)->execute($evaluation, collect([$media]));

        Event::assertDispatched(
            MediaAssessmentCompleted::class,
            fn (MediaAssessmentCompleted $event) => $event->evaluation->id === $evaluation->id
                && $event->assessments->count() === 1
                && $event->assessments->first()->event_media_context_id === $media->id
        );
    }

    public function test_event_not_dispatched_when_all_assessments_already_exist(): void
    {
        [$evaluation, $media] = $this->makeEvaluationWithMedia();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
        ]);

        Event::fake([MediaAssessmentCompleted::class]);

        app(EvaluateEventMultimodally::class)->execute($evaluation->fresh(), collect([$media]));

        Event::assertNotDispatched(MediaAssessmentCompleted::class);
    }

    public function test_agent_failure_still_dispatches_event_for_unavailable_assessment(): void
    {
        $agent = app(MediaAssessmentAgent::class);
        $this->assertInstanceOf(NullMediaAssessmentAgent::class, $agent);
        $agent->shouldFail = true;

        Event::fake([MediaAssessmentCompleted::class]);

        [$evaluation, $media] = $this->makeEvaluationWithMedia();

        app(EvaluateEventMultimodally::class)->execute($evaluation, collect([$media]));

        Event::assertDispatched(MediaAssessmentCompleted::class);
    }
}
