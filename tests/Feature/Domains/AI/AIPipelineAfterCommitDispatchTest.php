<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Listeners\AssessPendingMediaOnEvaluationCompleted;
use App\Domains\AI\Listeners\EvaluateMediaOnEventMediaAvailable;
use App\Domains\AI\Listeners\EvaluateOnEventContextBuilt;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Jobs\RunDecisionEngineJob;
use App\Domains\Decisions\Listeners\RunDecisionEngineOnAIEvaluationCompleted;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Los jobs del pipeline IA se despachan desde listeners síncronos que corren
 * DENTRO de la transacción que crea el registro que el job va a leer. Si el
 * worker arranca antes del commit, no encuentra nada y retorna en silencio:
 * un botón de pánico se quedaría sin decisión ni incidente.
 */
class AIPipelineAfterCommitDispatchTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    public function test_every_queue_connection_dispatches_after_commit(): void
    {
        foreach (['database', 'beanstalkd', 'sqs', 'redis'] as $connection) {
            $this->assertTrue(
                config("queue.connections.{$connection}.after_commit"),
                "La conexión {$connection} debe despachar tras el commit.",
            );
        }
    }

    public function test_decision_engine_job_is_dispatched_after_commit(): void
    {
        Queue::fake();

        $evaluation = $this->evaluation();

        (new RunDecisionEngineOnAIEvaluationCompleted)->handle(new AIEvaluationCompleted($evaluation));

        Queue::assertPushed(RunDecisionEngineJob::class, fn (RunDecisionEngineJob $job) => $job->afterCommit === true);
    }

    public function test_decision_engine_does_not_run_when_the_evaluation_transaction_rolls_back(): void
    {
        $this->mock(EvaluateDecisionRules::class)->shouldNotReceive('execute');

        $evaluation = $this->evaluation();

        try {
            DB::transaction(function () use ($evaluation): void {
                (new RunDecisionEngineOnAIEvaluationCompleted)->handle(new AIEvaluationCompleted($evaluation));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->addToAssertionCount(1);
    }

    public function test_decision_engine_runs_once_the_evaluation_transaction_commits(): void
    {
        $this->mock(EvaluateDecisionRules::class)->shouldReceive('execute')->once();

        $evaluation = $this->evaluation();

        DB::transaction(function () use ($evaluation): void {
            (new RunDecisionEngineOnAIEvaluationCompleted)->handle(new AIEvaluationCompleted($evaluation));
        });
    }

    public function test_evaluate_event_job_is_dispatched_after_commit(): void
    {
        Queue::fake();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);
        $snapshot = EventContextSnapshot::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);
        $profile = OperationalContextProfile::factory()->create(['team_id' => $this->teamId]);

        app(EvaluateOnEventContextBuilt::class)->handle(new EventContextBuilt($snapshot, $profile));

        Queue::assertPushed(
            EvaluateEventJob::class,
            fn (EvaluateEventJob $job) => $job->normalizedEventId === $event->id && $job->afterCommit === true,
        );
    }

    public function test_media_jobs_are_dispatched_after_commit(): void
    {
        Queue::fake();

        $evaluation = $this->evaluation();
        $media = EventMediaContext::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $evaluation->normalized_event_id,
        ]);

        app(AssessPendingMediaOnEvaluationCompleted::class)->handle(new AIEvaluationCompleted($evaluation));
        app(EvaluateMediaOnEventMediaAvailable::class)->handle(
            new EventMediaAvailable($media, NormalizedEvent::query()->findOrFail($evaluation->normalized_event_id)),
        );

        Queue::assertPushed(EvaluateEventMediaJob::class, 2);
        Queue::assertNotPushed(EvaluateEventMediaJob::class, fn (EvaluateEventMediaJob $job) => $job->afterCommit !== true);
    }

    private function evaluation(): AIEventEvaluation
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->teamId]);

        return AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'normalized_event_id' => $event->id,
        ]);
    }
}
