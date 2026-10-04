<?php

namespace Tests\Feature\Domains\AI\Clef;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Jobs\ShadowEvaluateWithClefJob;
use App\Domains\AI\Models\AIShadowEvaluation;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Feature\Domains\AI\Clef\Concerns\BuildsClefFixtures;
use Tests\TestCase;

class ClefBackfillCommandTest extends TestCase
{
    use AssertsSystemLog, BuildsClefFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ShadowEvaluateWithClefJob::class]);
        config(['services.cloudflare.account_id' => 'acc', 'services.cloudflare.auth_token' => 'tok', 'ai.clef.models' => ['clef', 'clef-flash']]);
    }

    public function test_dispatches_pending_evaluations_verdicted_first_and_reports_cost(): void
    {
        $team = Team::factory()->create();
        $plain = $this->makeEvaluation($team);
        $verdicted = $this->makeEvaluation($team, evaluation: ['operator_verdict' => OperatorVerdict::Confirmed]);
        $this->makeEvaluation($team, evaluation: ['evaluation_mode' => EvaluationMode::RulesOnly]);
        $done = $this->makeEvaluation($team);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $done->id, 'model' => 'clef']);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $done->id, 'model' => 'clef-flash']);

        $this->artisan('ai:clef-backfill', ['--force' => true])
            ->expectsOutputToContain('2 evaluaciones')
            ->assertSuccessful();

        $pushed = [];
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, function (ShadowEvaluateWithClefJob $job) use (&$pushed) {
            $pushed[] = $job->evaluationId;

            return $job->source === 'backfill';
        });
        $this->assertSame([$verdicted->id, $plain->id], $pushed);
        $this->assertSystemLogged('ai.clef_backfill.planned', fn (array $c) => $c['calc']['evaluations'] === 2 && abs($c['calc']['estimated_cost'] - 0.00185) < 0.00001);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_partially_evaluated_evaluation_is_still_pending(): void
    {
        $evaluation = $this->makeEvaluation(Team::factory()->create());
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $evaluation->id, 'model' => 'clef']);

        $this->artisan('ai:clef-backfill', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 1);
    }

    public function test_retryable_failures_are_pending_and_permanent_ones_are_not(): void
    {
        $team = Team::factory()->create();
        $retryable = $this->makeEvaluation($team);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $retryable->id, 'model' => 'clef']);
        AIShadowEvaluation::factory()->failed('timeout', retryable: true)->create(['ai_event_evaluation_id' => $retryable->id, 'model' => 'clef-flash']);
        $permanent = $this->makeEvaluation($team);
        AIShadowEvaluation::factory()->create(['ai_event_evaluation_id' => $permanent->id, 'model' => 'clef']);
        AIShadowEvaluation::factory()->failed('http_400')->create(['ai_event_evaluation_id' => $permanent->id, 'model' => 'clef-flash']);

        $this->artisan('ai:clef-backfill', ['--force' => true])->assertSuccessful();

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 1);
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, fn (ShadowEvaluateWithClefJob $job) => $job->evaluationId === $retryable->id);
    }

    public function test_only_the_version_the_report_scores_is_backfilled(): void
    {
        $team = Team::factory()->create();
        // Evento sin veredicto: se califica la última versión.
        $old = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 1]);
        $latest = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 2]);
        $latest->forceFill(['normalized_event_id' => $old->normalized_event_id])->save();
        // Evento con veredicto en v1: se califica v1, no la reevaluación.
        $labeled = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 1, 'operator_verdict' => OperatorVerdict::FalsePositive]);
        $after = $this->makeEvaluation($team, evaluation: ['evaluation_version' => 2]);
        $after->forceFill(['normalized_event_id' => $labeled->normalized_event_id])->save();

        $this->artisan('ai:clef-backfill', ['--force' => true])->assertSuccessful();

        $pushed = [];
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, function (ShadowEvaluateWithClefJob $job) use (&$pushed) {
            $pushed[] = $job->evaluationId;

            return true;
        });
        $this->assertEqualsCanonicalizing([$latest->id, $labeled->id], $pushed);
    }

    public function test_without_team_each_job_carries_its_own_evaluations_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $evaluationA = $this->makeEvaluation($teamA);
        $evaluationB = $this->makeEvaluation($teamB);

        $this->artisan('ai:clef-backfill', ['--force' => true])->assertSuccessful();

        $pairs = [];
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, function (ShadowEvaluateWithClefJob $job) use (&$pairs) {
            $pairs[$job->evaluationId] = $job->teamId;

            return true;
        });
        ksort($pairs);
        $this->assertSame([$evaluationA->id => $teamA->id, $evaluationB->id => $teamB->id], $pairs);
    }

    public function test_asks_for_confirmation_without_force(): void
    {
        $this->makeEvaluation(Team::factory()->create());

        $this->artisan('ai:clef-backfill')
            ->expectsConfirmation('¿Lanzar el backfill?', 'no')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_refuses_without_credentials(): void
    {
        config(['services.cloudflare.auth_token' => null]);

        $this->artisan('ai:clef-backfill', ['--force' => true])->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_team_option_only_touches_that_team(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $this->makeEvaluation($teamA);
        $this->makeEvaluation($teamB);

        $this->artisan('ai:clef-backfill', ['--team' => $teamA->id, '--force' => true])->assertSuccessful();

        Queue::assertPushed(ShadowEvaluateWithClefJob::class, 1);
        Queue::assertPushed(ShadowEvaluateWithClefJob::class, fn (ShadowEvaluateWithClefJob $job) => $job->teamId === $teamA->id);
    }
}
