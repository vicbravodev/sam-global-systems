<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Jobs\RunDecisionEngineJob;
use App\Domains\Decisions\Listeners\RunDecisionEngineOnAIEvaluationCompleted;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RunDecisionEngineJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
    }

    public function test_listener_dispatches_run_decision_engine_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $user->currentTeam->id,
        ]);

        (new RunDecisionEngineOnAIEvaluationCompleted)->handle(new AIEvaluationCompleted($eval));

        Bus::assertDispatched(
            RunDecisionEngineJob::class,
            fn (RunDecisionEngineJob $job) => $job->aiEvaluationId === $eval->id,
        );
    }

    public function test_job_is_idempotent_for_same_evaluation_id(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $teamId,
            'classification' => EventClassification::RealEvent,
            'risk_score' => 0.7,
            'confidence_score' => 0.9,
        ]);

        RuleSet::factory()->global()->create(['code' => 'default']);

        $action = app(EvaluateDecisionRules::class);

        (new RunDecisionEngineJob($eval->id))->handle($action);
        (new RunDecisionEngineJob($eval->id))->handle($action);

        $this->assertSame(
            1,
            Decision::withoutGlobalScopes()->where('ai_evaluation_id', $eval->id)->count(),
        );
    }

    public function test_job_no_ops_when_evaluation_missing(): void
    {
        (new RunDecisionEngineJob(999999))->handle(app(EvaluateDecisionRules::class));

        $this->assertSame(0, Decision::withoutGlobalScopes()->count());
    }

    public function test_job_unique_id_is_evaluation_id(): void
    {
        $job = new RunDecisionEngineJob(42);

        $this->assertSame('42', $job->uniqueId());
    }
}
