<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\MediaAssessmentAgent;
use App\Contracts\NullImplementations\NullMediaAssessmentAgent;
use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Enums\MediaAssessmentResult;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Enums\MediaType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * La misma imagen no se evalúa (ni se paga) dos veces: ni al re-evaluar el
 * evento en otra versión, ni cuando dos jobs se cruzan sobre la misma media.
 */
class MediaAssessmentNoDoublePayTest extends TestCase
{
    use RefreshDatabase;

    private NullMediaAssessmentAgent $agent;

    private AIEventEvaluation $v1;

    private AIEventEvaluation $v2;

    private EventMediaContext $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);

        $this->agent = new NullMediaAssessmentAgent;
        $this->app->instance(MediaAssessmentAgent::class, $this->agent);

        $teamId = User::factory()->create()->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);

        $this->v1 = AIEventEvaluation::factory()->create([
            'team_id' => $teamId,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 1,
        ]);
        $this->v2 = AIEventEvaluation::factory()->create([
            'team_id' => $teamId,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 2,
        ]);
        $this->media = EventMediaContext::factory()->create([
            'team_id' => $teamId,
            'normalized_event_id' => $event->id,
            'media_type' => MediaType::Image,
        ]);
    }

    public function test_media_assessed_under_a_previous_version_is_reused_not_paid_again(): void
    {
        $action = app(EvaluateEventMultimodally::class);

        $action->execute($this->v1, collect([$this->media]));
        $this->assertCount(1, $this->agent->receivedInputs);

        $result = $action->execute($this->v2, collect([$this->media]));

        $this->assertCount(1, $this->agent->receivedInputs, 'the model must not be called again');
        $this->assertSame(1, AIMediaAssessment::where('event_media_context_id', $this->media->id)->count());
        $this->assertCount(1, $result);
    }

    public function test_unavailable_previous_assessment_is_retried(): void
    {
        AIMediaAssessment::factory()->create([
            'evaluation_id' => $this->v1->id,
            'event_media_context_id' => $this->media->id,
            'result' => MediaAssessmentResult::Unavailable,
        ]);

        app(EvaluateEventMultimodally::class)->execute($this->v2, collect([$this->media]));

        $this->assertCount(1, $this->agent->receivedInputs);
        $this->assertTrue(AIMediaAssessment::where('evaluation_id', $this->v2->id)
            ->where('event_media_context_id', $this->media->id)
            ->exists());
    }

    public function test_media_locked_by_another_job_is_skipped(): void
    {
        $lock = Cache::lock('ai-media-assessment:'.$this->media->id, 240);
        $this->assertTrue($lock->get());

        try {
            app(EvaluateEventMultimodally::class)->execute($this->v2, collect([$this->media]));
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->agent->receivedInputs);
        $this->assertSame(0, AIMediaAssessment::where('event_media_context_id', $this->media->id)->count());
    }

    public function test_lock_is_released_after_assessing(): void
    {
        app(EvaluateEventMultimodally::class)->execute($this->v1, collect([$this->media]));

        $this->assertTrue(Cache::lock('ai-media-assessment:'.$this->media->id, 1)->get());
    }
}
