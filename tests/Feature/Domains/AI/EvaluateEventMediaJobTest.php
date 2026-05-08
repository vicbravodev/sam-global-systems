<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\EvaluateEventMultimodally;
use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Listeners\EvaluateMediaOnEventMediaAvailable;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EvaluateEventMediaJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_listener_dispatches_media_job_when_evaluation_exists(): void
    {
        Bus::fake();

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

        (new EvaluateMediaOnEventMediaAvailable)->handle(new EventMediaAvailable($media, $event));

        Bus::assertDispatched(
            EvaluateEventMediaJob::class,
            fn (EvaluateEventMediaJob $job) => $job->evaluationId === $evaluation->id
                && $job->mediaContextIds === [$media->id]
        );
    }

    public function test_listener_no_ops_when_no_evaluation_yet(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        (new EvaluateMediaOnEventMediaAvailable)->handle(new EventMediaAvailable($media, $event));

        Bus::assertNotDispatched(EvaluateEventMediaJob::class);
    }

    public function test_listener_targets_latest_evaluation_version(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 1,
        ]);
        $latest = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 2,
        ]);

        $media = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        (new EvaluateMediaOnEventMediaAvailable)->handle(new EventMediaAvailable($media, $event));

        Bus::assertDispatched(
            EvaluateEventMediaJob::class,
            fn (EvaluateEventMediaJob $job) => $job->evaluationId === $latest->id
        );
    }

    public function test_job_runs_on_ai_evaluation_queue(): void
    {
        $job = new EvaluateEventMediaJob(1, [10]);

        $this->assertSame('ai-evaluation', $job->queue);
    }

    public function test_job_processes_media_and_creates_assessment(): void
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

        (new EvaluateEventMediaJob($evaluation->id, [$media->id]))
            ->handle(app(EvaluateEventMultimodally::class));

        $this->assertSame(1, AIMediaAssessment::query()
            ->where('evaluation_id', $evaluation->id)
            ->where('event_media_context_id', $media->id)
            ->count());
    }

    public function test_job_no_ops_when_evaluation_missing(): void
    {
        (new EvaluateEventMediaJob(999_999, [1]))
            ->handle(app(EvaluateEventMultimodally::class));

        $this->assertSame(0, AIMediaAssessment::query()->count());
    }

    public function test_job_no_ops_when_media_collection_empty(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);

        (new EvaluateEventMediaJob($evaluation->id, []))
            ->handle(app(EvaluateEventMultimodally::class));

        $this->assertSame(0, AIMediaAssessment::query()->count());
    }

    public function test_job_skips_media_belonging_to_other_event(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        $otherEvent = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
        ]);
        $foreignMedia = EventMediaContext::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $otherEvent->id,
        ]);

        (new EvaluateEventMediaJob($evaluation->id, [$foreignMedia->id]))
            ->handle(app(EvaluateEventMultimodally::class));

        $this->assertSame(0, AIMediaAssessment::query()->count());
    }
}
