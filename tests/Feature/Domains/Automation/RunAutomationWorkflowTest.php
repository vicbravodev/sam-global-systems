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
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Models\User;
use Database\Seeders\AutomationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RunAutomationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AutomationMeterSeeder::class);
    }

    public function test_creates_workflow_execution_and_dispatches_jobs_per_step(): void
    {
        Bus::fake();

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
}
