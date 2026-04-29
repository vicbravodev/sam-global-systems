<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Jobs\ExecuteActionJob;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\AutomationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AutomationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(AutomationMeterSeeder::class);
    }

    public function test_index_lists_workflows_for_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        AutomationWorkflow::factory()->count(2)->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/automation/workflows");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_store_creates_workflow_for_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/automation/workflows", [
            'code' => 'wf_high_severity',
            'name' => 'High severity escalation',
            'trigger_type' => WorkflowTriggerType::IncidentCreated->value,
            'status' => WorkflowStatus::Active->value,
            'steps_json' => [[
                'order' => 1,
                'action_type' => ActionType::SendEmail->value,
                'execution_mode' => 'async',
            ]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('automation_workflows', [
            'team_id' => $team->id,
            'code' => 'wf_high_severity',
        ]);
    }

    public function test_trigger_endpoint_dispatches_workflow_run(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $workflow = AutomationWorkflow::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/{$team->slug}/automation/workflows/{$workflow->id}/trigger",
            ['source_reference_id' => 'manual-1'],
        );

        $response->assertStatus(202);
        $this->assertSame(1, WorkflowExecution::withoutGlobalScopes()->count());
    }

    public function test_trigger_endpoint_returns_409_when_already_executed(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $workflow = AutomationWorkflow::factory()->create(['team_id' => $team->id]);

        WorkflowExecution::factory()->create([
            'team_id' => $team->id,
            'automation_workflow_id' => $workflow->id,
            'source_type' => ActionExecutionSourceType::Manual->value,
            'source_reference_id' => 'dup',
        ]);

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/{$team->slug}/automation/workflows/{$workflow->id}/trigger",
            ['source_reference_id' => 'dup'],
        );

        $response->assertStatus(409);
    }

    public function test_cross_tenant_workflow_show_is_blocked(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $workflow = AutomationWorkflow::factory()->create(['team_id' => $userA->currentTeam->id]);

        $this->actingAs($userB);

        $response = $this->getJson("/api/{$userA->currentTeam->slug}/automation/workflows/{$workflow->id}");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_executions_index_returns_paginated_results(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        ActionExecution::factory()->count(2)->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/automation/executions");

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_confirm_endpoint_dispatches_execute_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $execution = ActionExecution::factory()
            ->requiresConfirmation()
            ->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/automation/executions/{$execution->id}/confirm");

        $response->assertStatus(202);
        $this->assertSame(ActionExecutionStatus::Queued, $execution->fresh()->status);

        Bus::assertDispatched(ExecuteActionJob::class);
    }

    public function test_cancel_endpoint_marks_execution_cancelled(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $execution = ActionExecution::factory()
            ->status(ActionExecutionStatus::Queued)
            ->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/automation/executions/{$execution->id}/cancel");

        $response->assertOk();
        $this->assertSame(ActionExecutionStatus::Cancelled, $execution->fresh()->status);
    }

    public function test_retry_endpoint_requeues_failed_execution(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $execution = ActionExecution::factory()
            ->failed()
            ->create([
                'team_id' => $team->id,
                'attempts' => 1,
            ]);

        $this->actingAs($user);

        $response = $this->postJson("/api/{$team->slug}/automation/executions/{$execution->id}/retry");

        $response->assertStatus(202);
        Bus::assertDispatched(ExecuteActionJob::class);
    }
}
