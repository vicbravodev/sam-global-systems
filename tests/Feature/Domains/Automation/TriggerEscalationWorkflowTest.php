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
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class TriggerEscalationWorkflowTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

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

        $this->assertCount(1, $this->systemLogEntries('automation.workflow.matched'));
        $this->assertSystemLogged('automation.workflow.matched', fn (array $c) => $c['input']['automation_workflow_id'] === $matching->id
            && $c['input']['workflow_scope'] === 'tenant'
            && $c['input']['trigger_type'] === WorkflowTriggerType::IncidentCreated->value
            && $c['calc']['conditions_count'] === 1
            && $c['result']['job_requested'] === true);

        $this->assertSystemLogged('automation.workflow.not_matched', fn (array $c) => $c['reason'] === 'condition_mismatch'
            && $c['input']['automation_workflow_id'] === $nonMatchingByCondition->id
            && $c['calc']['failed_key'] === 'severity'
            && $c['calc']['expected'] === 'low'
            && $c['calc']['actual'] === 'high');

        $this->assertSystemLogged('automation.trigger.evaluated', fn (array $c) => $c['input']['source_reference_id'] === '99'
            && $c['calc']['candidates_count'] === 2
            && $c['result']['matched_workflow_ids'] === [$matching->id]
            && $c['result']['matched_count'] === 1);

        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_strict_type_mismatch_logs_the_types_of_both_values(): void
    {
        Bus::fake();

        $teamId = User::factory()->create()->currentTeam->id;

        $workflow = AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->create([
                'team_id' => $teamId,
                'trigger_conditions_json' => ['incident_type_id' => '12'],
            ]);

        $dispatched = app(TriggerEscalationWorkflow::class)->execute(
            teamId: $teamId,
            triggerType: WorkflowTriggerType::IncidentCreated,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: '99',
            payload: ['incident_type_id' => 12],
        );

        $this->assertSame([], $dispatched);
        Bus::assertNotDispatched(RunAutomationWorkflowJob::class);

        // Mismo texto, distinto tipo: la comparación es estricta.
        $c = $this->assertSystemLogged('automation.workflow.not_matched', fn (array $c) => $c['input']['automation_workflow_id'] === $workflow->id);
        $this->assertSame('condition_mismatch', $c['reason']);
        $this->assertSame('incident_type_id', $c['calc']['failed_key']);
        $this->assertSame('12', $c['calc']['expected']);
        $this->assertSame('12', $c['calc']['actual']);
        $this->assertSame('string', $c['calc']['expected_type']);
        $this->assertSame('int', $c['calc']['actual_type']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_free_text_condition_values_never_reach_the_log(): void
    {
        Bus::fake();

        $teamId = User::factory()->create()->currentTeam->id;

        $workflow = AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->create([
                'team_id' => $teamId,
                'trigger_conditions_json' => ['severity' => 'texto libre con espacios'],
            ]);

        $dispatched = app(TriggerEscalationWorkflow::class)->execute(
            teamId: $teamId,
            triggerType: WorkflowTriggerType::IncidentCreated,
            sourceType: ActionExecutionSourceType::Incident,
            sourceReferenceId: '7',
            payload: ['severity' => 'high'],
        );

        $this->assertSame([], $dispatched);

        $this->assertSystemLogged('automation.workflow.not_matched', fn (array $c) => $c['input']['automation_workflow_id'] === $workflow->id
            && $c['calc']['failed_key'] === 'severity'
            && $c['calc']['expected'] === null
            && $c['calc']['actual'] === 'high');
        $this->assertSystemLogged('automation.trigger.evaluated', fn (array $c) => $c['result']['matched_count'] === 0);
        $this->assertStringNotContainsString('texto libre con espacios', json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_rolled_back_incident_never_logs_the_matched_workflow(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;

        AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->create(['team_id' => $teamId, 'trigger_conditions_json' => []]);

        Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'));

        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);

        try {
            app(CreateIncidentFromEvent::class)->execute($event, ['incident_type_code' => 'panic_emergency', 'priority_code' => 'critical']);
            $this->fail('La creación debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
        $this->assertSystemNotLogged('automation.workflow.matched');
        $this->assertSystemNotLogged('automation.trigger.evaluated');
        $this->assertSystemLogged('incidents.type.resolved');
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

        $this->assertSystemLogged('automation.workflow.matched', fn (array $c) => $c['input']['automation_workflow_id'] === $systemWide->id
            && $c['input']['workflow_scope'] === 'global'
            && $c['calc']['conditions_count'] === 0);
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
