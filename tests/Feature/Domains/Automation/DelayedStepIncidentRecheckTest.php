<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Actions\ExecuteAction;
use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * P1-8: un paso de automatización con retraso revalida el incidente al
 * ejecutarse; si ya se cerró o un humano lo tomó, se cancela con motivo.
 */
class DelayedStepIncidentRecheckTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
        $this->seed(NotificationMeterSeeder::class);
        Mail::fake();

        NotificationChannel::factory()->email()->create(['is_active' => true]);
    }

    public function test_step_is_cancelled_when_a_human_claimed_the_incident(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $execution = $this->delayedEmailStep($team, $incident);

        $this->runJob($execution);

        $execution->refresh();
        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->status);
        $this->assertStringContainsString('human control', (string) $execution->error_message);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());

        $this->assertSystemLogged('automation.action.stopped', fn (array $c) => $c['reason'] === 'human_control'
            && $c['input']['action_execution_id'] === $execution->id
            && $c['calc']['incident_id'] === $incident->id
            && $c['calc']['delayed'] === true);
        $this->assertSystemNotLogged('automation.action.completed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_step_is_cancelled_when_the_incident_is_terminal(): void
    {
        $team = User::factory()->create()->currentTeam;
        $resolved = IncidentStatus::query()->where('is_terminal', true)->firstOrFail();
        $incident = Incident::factory()->create(['team_id' => $team->id, 'incident_status_id' => $resolved->id]);

        $execution = $this->delayedEmailStep($team, $incident);

        $this->runJob($execution);

        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->fresh()->status);
        $this->assertSame(0, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());

        $this->assertSystemLogged('automation.action.stopped', fn (array $c) => $c['reason'] === 'incident_terminal'
            && $c['calc']['incident_id'] === $incident->id);
    }

    public function test_step_still_runs_while_the_incident_is_open_and_unclaimed(): void
    {
        $team = User::factory()->create()->currentTeam;
        $open = IncidentStatus::query()->where('code', 'open')->firstOrFail();
        $incident = Incident::factory()->create(['team_id' => $team->id, 'incident_status_id' => $open->id]);

        $execution = $this->delayedEmailStep($team, $incident);

        $this->runJob($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $execution->fresh()->status);
        $this->assertSame(1, Notification::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_human_confirmed_step_is_not_second_guessed(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id, 'claimed_by_user_id' => $user->id]);

        $execution = $this->delayedEmailStep($team, $incident, ExecutionMode::RequiresConfirmation);

        $this->runJob($execution);

        $this->assertSame(ActionExecutionStatus::Completed, $execution->fresh()->status);
    }

    public function test_recheck_never_reads_another_tenants_workflow_or_incident(): void
    {
        // Team A: incidente tomado por un humano y su WorkflowExecution.
        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;
        $incidentA = Incident::factory()->create(['team_id' => $teamA->id, 'claimed_by_user_id' => $userA->id]);
        $workflowA = WorkflowExecution::factory()->create([
            'team_id' => $teamA->id,
            'source_type' => ActionExecutionSourceType::Incident->value,
            'source_reference_id' => (string) $incidentA->id,
        ]);

        // Team B: un paso que apunta (mal) al WorkflowExecution de A por id.
        $teamB = User::factory()->create()->currentTeam;
        $execution = ActionExecution::factory()->create([
            'team_id' => $teamB->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Queued,
            'source_type' => ActionExecutionSourceType::Workflow,
            'source_reference_id' => (string) $workflowA->id,
            'target_type' => 'email',
            'target_reference' => 'ops-b@example.test',
        ]);

        $this->assertNoTenantLeak($teamB, fn () => $this->runJob($execution));

        // El estado de A no afecta a B: el paso de B corre.
        $this->assertSame(ActionExecutionStatus::Completed, $execution->fresh()->status);
    }

    private function delayedEmailStep(Team $team, Incident $incident, ExecutionMode $mode = ExecutionMode::Async): ActionExecution
    {
        $workflowExecution = WorkflowExecution::factory()->create([
            'team_id' => $team->id,
            'source_type' => ActionExecutionSourceType::Incident->value,
            'source_reference_id' => (string) $incident->id,
        ]);

        return ActionExecution::factory()->create([
            'team_id' => $team->id,
            'action_type' => ActionType::SendEmail,
            'status' => ActionExecutionStatus::Queued,
            'execution_mode' => $mode,
            'source_type' => ActionExecutionSourceType::Workflow,
            'source_reference_id' => (string) $workflowExecution->id,
            'target_type' => 'email',
            'target_reference' => 'ops@example.test',
        ]);
    }

    private function runJob(ActionExecution $execution): void
    {
        (new ExecuteActionJob($execution->id))->handle(app(ExecuteAction::class));
    }
}
