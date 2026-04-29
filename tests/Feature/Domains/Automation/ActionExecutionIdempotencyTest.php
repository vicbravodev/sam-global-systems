<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Listeners\TriggerAutomationOnIncidentCreated;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Models\User;
use Database\Seeders\AutomationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeIncidentCreatedEvent;
use Tests\TestCase;

class ActionExecutionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AutomationMeterSeeder::class);
    }

    public function test_same_event_does_not_create_duplicate_workflow_execution(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        AutomationWorkflow::factory()
            ->trigger(WorkflowTriggerType::IncidentCreated)
            ->withSteps([
                [
                    'order' => 1,
                    'action_type' => ActionType::SendEmail->value,
                    'execution_mode' => 'async',
                    'target_type' => 'role',
                    'target_reference' => 'tenant_admin',
                ],
            ])
            ->create(['team_id' => $teamId, 'trigger_conditions_json' => []]);

        // Synchronous queue + listeners are real; just call the listener twice with the same event.
        $listener = app(TriggerAutomationOnIncidentCreated::class);
        $event = new FakeIncidentCreatedEvent(teamId: $teamId, incidentId: 99, payload: []);

        $listener->handle($event);
        $listener->handle($event);

        $this->assertSame(1, WorkflowExecution::withoutGlobalScopes()->count());
    }

    public function test_action_execution_is_unique_per_source_and_target(): void
    {
        $user = User::factory()->create();
        $teamId = $user->currentTeam->id;

        $payload = [
            'team_id' => $teamId,
            'source_type' => ActionExecutionSourceType::Workflow->value,
            'source_reference_id' => '42',
            'action_type' => ActionType::SendEmail->value,
            'target_reference' => 'tenant_admin',
        ];

        $first = ActionExecution::firstOrCreate($payload, ['execution_mode' => 'async', 'status' => 'queued']);
        $second = ActionExecution::firstOrCreate($payload, ['execution_mode' => 'async', 'status' => 'queued']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ActionExecution::withoutGlobalScopes()->count());
    }
}
