<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_execution_is_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        ActionExecution::factory()->create(['team_id' => $userA->currentTeam->id]);
        ActionExecution::factory()->create(['team_id' => $userB->currentTeam->id]);

        $this->actingAs($userA);

        $this->assertSame(1, ActionExecution::query()->count());
        $this->assertSame(2, ActionExecution::withoutGlobalScopes()->count());
    }

    public function test_workflow_execution_is_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $workflowA = AutomationWorkflow::factory()->create(['team_id' => $userA->currentTeam->id]);
        $workflowB = AutomationWorkflow::factory()->create(['team_id' => $userB->currentTeam->id]);

        WorkflowExecution::factory()->create([
            'team_id' => $userA->currentTeam->id,
            'automation_workflow_id' => $workflowA->id,
        ]);
        WorkflowExecution::factory()->create([
            'team_id' => $userB->currentTeam->id,
            'automation_workflow_id' => $workflowB->id,
        ]);

        $this->actingAs($userA);

        $this->assertSame(1, WorkflowExecution::query()->count());
        $this->assertSame(2, WorkflowExecution::withoutGlobalScopes()->count());
    }
}
