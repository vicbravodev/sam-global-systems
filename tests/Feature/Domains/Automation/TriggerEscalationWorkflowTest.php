<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Jobs\RunAutomationWorkflowJob;
use App\Domains\Automation\Listeners\TriggerAutomationOnDecisionMade;
use App\Domains\Automation\Listeners\TriggerAutomationOnIncidentCreated;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Services\TriggerEscalationWorkflow;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TriggerEscalationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_dispatches_workflow_jobs_for_matching_active_workflows(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $matching = AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->create([
                'team_id' => $teamId,
                'trigger_conditions_json' => ['severity' => 'high'],
            ]);

        $nonMatchingByCondition = AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->create([
                'team_id' => $teamId,
                'trigger_conditions_json' => ['severity' => 'low'],
            ]);

        $inactive = AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->inactive()
            ->create(['team_id' => $teamId]);

        $otherTrigger = AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::DecisionOutcome)
            ->create(['team_id' => $teamId]);

        $dispatched = app(TriggerEscalationWorkflow::class)->execute(
            teamId: $teamId,
            triggerType: WorkflowTriggerType::IncidentCreated,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: '99',
            payload: ['severity' => 'high'],
        );

        $this->assertSame([$matching->id], $dispatched);

        Bus::assertDispatched(RunAutomationWorkflowJob::class, 1);
        Bus::assertDispatched(RunAutomationWorkflowJob::class, function (RunAutomationWorkflowJob $job) use ($matching) {
            return $job->automationWorkflowId === $matching->id;
        });
    }

    public function test_system_wide_workflow_is_picked_up_for_tenant(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $systemWide = AutomationWorkflow::factory()
            ->systemWide()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->create(['status' => WorkflowStatus::Active]);

        $dispatched = app(TriggerEscalationWorkflow::class)->execute(
            teamId: $teamId,
            triggerType: WorkflowTriggerType::IncidentCreated,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: '1',
            payload: [],
        );

        $this->assertSame([$systemWide->id], $dispatched);
    }

    public function test_decision_made_listener_uses_trigger_service(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::DecisionOutcome)
            ->create(['team_id' => $teamId]);

        $decision = Decision::factory()->create(['team_id' => $teamId]);

        app(TriggerAutomationOnDecisionMade::class)->handle(new DecisionMade($decision));

        Bus::assertDispatched(RunAutomationWorkflowJob::class);
    }

    public function test_incident_created_listener_uses_trigger_service(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->create([
                'team_id' => $teamId,
                'trigger_conditions_json' => [],
            ]);

        $incident = Incident::factory()->create(['team_id' => $teamId]);

        app(TriggerAutomationOnIncidentCreated::class)->handle(new IncidentCreated($incident));

        Bus::assertDispatched(RunAutomationWorkflowJob::class);
    }
}
