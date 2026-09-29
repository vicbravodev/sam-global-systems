<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Enums\WorkflowExecutionStatus;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Domains\Automation\Services\RunAutomationWorkflow;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
use Database\Seeders\AutomationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class RunAutomationWorkflowTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AutomationMeterSeeder::class);
    }

    public function test_creates_workflow_execution_and_dispatches_jobs_per_step(): void
    {
        Bus::fake();
        $this->freezeTime();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $workflow = AutomationWorkflow::factory()
            ->withSteps([
                [
                    'order' => 1,
                    'action_type' => ActionType::SendEmail->value,
                    'execution_mode' => ExecutionMode::Async->value,
                    'delay_seconds' => 0,
                    'target_type' => 'role',
                    'target_reference' => 'tenant_admin',
                ],
                [
                    'order' => 2,
                    'action_type' => ActionType::CreateTicket->value,
                    'execution_mode' => ExecutionMode::Async->value,
                    'delay_seconds' => 60,
                    'target_type' => 'incident',
                    'target_reference' => null,
                ],
            ])
            ->create(['team_id' => $teamId]);

        $execution = app(RunAutomationWorkflow::class)->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: 'manual-1',
        );

        $this->assertNotNull($execution);
        $this->assertSame(WorkflowExecutionStatus::Running, $execution->status);
        $this->assertSame(2, ActionExecution::withoutGlobalScopes()->count());

        Bus::assertDispatched(ExecuteActionJob::class, fn (ExecuteActionJob $job) => $job !== null);

        $context = $this->assertSystemLogged('automation.workflow.started', fn (array $c) => $c['input']['automation_workflow_id'] === $workflow->id
            && $c['input']['workflow_scope'] === 'tenant'
            && $c['input']['source_type'] === ActionExecutionSourceType::Manual->value
            && $c['input']['source_reference_id'] === 'manual-1'
            && $c['calc']['steps_count'] === 2
            && $c['calc']['queued_count'] === 2
            && $c['calc']['awaiting_confirmation_count'] === 0
            && $c['calc']['reused_count'] === 0
            && $c['calc']['incident_expected'] === false
            && $c['result']['workflow_execution_id'] === $execution->id
            && $c['result']['status'] === WorkflowExecutionStatus::Running->value
            && $c['result']['usage_event_key'] === "workflow_exec_{$execution->id}");

        // Cada demora registrada es la del ExecuteActionJob empujado (0 = sin delay).
        $pushedDelays = [];
        Bus::assertDispatched(ExecuteActionJob::class, function (ExecuteActionJob $job) use (&$pushedDelays) {
            $pushedDelays[] = $job->delay === null ? 0 : (int) now()->diffInSeconds($job->delay);

            return true;
        });
        $this->assertSame([0, 60], $context['calc']['cumulative_delays_seconds']);
        $this->assertSame($context['calc']['cumulative_delays_seconds'], $pushedDelays);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_workflow_without_steps_is_started_and_completed(): void
    {
        Bus::fake();

        $teamId = User::factory()->create()->currentTeam->id;

        $workflow = AutomationWorkflow::factory()->withSteps([])->create(['team_id' => $teamId]);

        $execution = app(RunAutomationWorkflow::class)->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: 'empty-1',
        );

        $this->assertSame(WorkflowExecutionStatus::Completed, $execution->status);
        $this->assertSystemLogged('automation.workflow.started', fn (array $c) => $c['calc']['steps_count'] === 0
            && $c['calc']['queued_count'] === 0
            && $c['calc']['cumulative_delays_seconds'] === []
            && $c['result']['status'] === 'completed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_execution_of_another_team_never_makes_this_team_skip_a_global_workflow(): void
    {
        Bus::fake();

        $teamId = User::factory()->create()->currentTeam->id;
        $otherTeamId = User::factory()->create()->currentTeam->id;

        $workflow = AutomationWorkflow::factory()->systemWide()->create();

        // Ejecución del otro tenant para la misma fuente (sin referencia):
        // sin TenantContext ambiente, sólo el filtro explícito la separa.
        $foreign = WorkflowExecution::withoutGlobalScopes()->create([
            'team_id' => $otherTeamId,
            'automation_workflow_id' => $workflow->id,
            'source_type' => ActionExecutionSourceType::Manual->value,
            'source_reference_id' => null,
            'status' => WorkflowExecutionStatus::Completed,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $execution = app(RunAutomationWorkflow::class)->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: null,
        );

        $this->assertNotNull($execution);
        $this->assertSame($teamId, (int) $execution->team_id);
        $this->assertNotSame($foreign->id, $execution->id);

        $this->assertSystemNotLogged('automation.workflow.skipped');
        $this->assertSystemLogged('automation.workflow.started', fn (array $c) => $c['input']['workflow_scope'] === 'global'
            && $c['result']['workflow_execution_id'] === $execution->id);

        $json = (string) json_encode($this->systemLogEntries());
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]*workflow_execution_id":'.$foreign->id.'\\b/', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_global_workflow_with_the_same_reference_runs_once_per_team_without_leaking(): void
    {
        Bus::fake();

        $teamId = User::factory()->create()->currentTeam->id;
        $otherTeamId = User::factory()->create()->currentTeam->id;

        $workflow = AutomationWorkflow::factory()->systemWide()->create();

        $service = app(RunAutomationWorkflow::class);

        $foreign = $service->execute(
            workflow: $workflow,
            teamId: $otherTeamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: 'ref-compartida',
        );

        // Misma clave (workflow, source_type, source_reference_id) en otro
        // tenant: el índice de idempotencia incluye team_id, así que no choca
        // y no toca ni una fila del otro tenant.
        $execution = $this->assertNoTenantLeak($teamId, fn () => $service->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: 'ref-compartida',
        ));

        $this->assertNotNull($foreign);
        $this->assertNotNull($execution);
        $this->assertSame($teamId, (int) $execution->team_id);
        $this->assertNotSame($foreign->id, $execution->id);

        // Dentro del mismo tenant sigue siendo idempotente.
        $this->assertNull($service->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: 'ref-compartida',
        ));
        $this->assertSame(2, WorkflowExecution::withoutGlobalScopes()->count());
    }

    public function test_idempotent_when_called_twice_for_same_source(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $workflow = AutomationWorkflow::factory()->create(['team_id' => $teamId]);

        $service = app(RunAutomationWorkflow::class);

        $first = $service->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: 'incident-42',
        );

        $second = $service->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: 'incident-42',
        );

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, WorkflowExecution::withoutGlobalScopes()->count());

        $this->assertCount(1, $this->systemLogEntries('automation.workflow.started'));
        $this->assertSystemLogged('automation.workflow.skipped', fn (array $c) => $c['reason'] === 'already_ran'
            && $c['input']['automation_workflow_id'] === $workflow->id
            && $c['input']['source_reference_id'] === 'incident-42'
            && $c['result']['existing_workflow_execution_id'] === $first->id);
    }

    public function test_steps_with_requires_confirmation_pause_in_pending(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $workflow = AutomationWorkflow::factory()
            ->withSteps([
                [
                    'order' => 1,
                    'action_type' => ActionType::CreateTicket->value,
                    'execution_mode' => ExecutionMode::RequiresConfirmation->value,
                    'delay_seconds' => 0,
                    'target_type' => null,
                    'target_reference' => null,
                ],
            ])
            ->create(['team_id' => $teamId]);

        app(RunAutomationWorkflow::class)->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: 'pause-1',
        );

        $execution = ActionExecution::withoutGlobalScopes()->first();

        $this->assertNotNull($execution);
        $this->assertSame(ActionExecutionStatus::Pending, $execution->status);
        Bus::assertNotDispatched(ExecuteActionJob::class);

        $this->assertSystemLogged('automation.workflow.started', fn (array $c) => $c['calc']['steps_count'] === 1
            && $c['calc']['awaiting_confirmation_count'] === 1
            && $c['calc']['queued_count'] === 0);
    }

    public function test_emits_usage_event_for_workflow_execution(): void
    {
        Event::fake([UsageRecorded::class]);
        Bus::fake();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $workflow = AutomationWorkflow::factory()->create(['team_id' => $teamId]);

        app(RunAutomationWorkflow::class)->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Manual,
            sourceReferenceId: 'usage-1',
        );

        Event::assertDispatched(UsageRecorded::class, fn (UsageRecorded $ev) => $ev->meterCode === 'incident_workflows');
    }

    public function test_steps_inherit_the_incident_that_triggered_the_workflow(): void
    {
        Bus::fake();

        $teamId = User::factory()->create()->currentTeam->id;
        $incident = Incident::factory()->create(['team_id' => $teamId]);

        $workflow = AutomationWorkflow::factory()
            ->withSteps([
                ['order' => 1, 'action_type' => ActionType::Escalate->value, 'execution_mode' => ExecutionMode::Async->value],
                ['order' => 2, 'action_type' => ActionType::SendEmail->value, 'execution_mode' => ExecutionMode::Async->value, 'target_type' => 'role', 'target_reference' => 'admin'],
            ])
            ->create(['team_id' => $teamId]);

        app(RunAutomationWorkflow::class)->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
        );

        $this->assertSystemLogged('automation.workflow.started', fn (array $c) => $c['calc']['incident_expected'] === true
            && $c['calc']['incident_linked'] === true);

        $this->assertSame(
            [$incident->id, $incident->id],
            ActionExecution::withoutGlobalScopes()->where('team_id', $teamId)->orderBy('id')->pluck('incident_id')->all(),
        );
    }

    public function test_steps_never_link_an_incident_of_another_team(): void
    {
        Bus::fake();

        $teamId = User::factory()->create()->currentTeam->id;
        $foreign = Incident::factory()->create();

        $workflow = AutomationWorkflow::factory()->create(['team_id' => $teamId]);

        app(RunAutomationWorkflow::class)->execute(
            workflow: $workflow,
            teamId: $teamId,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: (string) $foreign->id,
        );

        $this->assertNull(
            ActionExecution::withoutGlobalScopes()->where('team_id', $teamId)->value('incident_id'),
        );

        $this->assertSystemLogged('automation.workflow.started', fn (array $c) => $c['calc']['incident_expected'] === true
            && $c['calc']['incident_linked'] === false);
    }
}
