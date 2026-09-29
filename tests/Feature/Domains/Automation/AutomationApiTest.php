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

    public function test_store_rejects_duplicate_workflow_code_for_same_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $payload = [
            'code' => 'wf_duplicated',
            'name' => 'Workflow duplicado',
            'trigger_type' => WorkflowTriggerType::IncidentCreated->value,
            'status' => WorkflowStatus::Active->value,
            'steps_json' => [[
                'order' => 1,
                'action_type' => ActionType::SendEmail->value,
                'execution_mode' => 'async',
            ]],
        ];

        $this->postJson("/api/{$team->slug}/automation/workflows", $payload)
            ->assertCreated();

        // D-02: el segundo POST con el mismo code (doble click) debe fallar
        // con 422, no crear un segundo workflow.
        $duplicate = $this->postJson("/api/{$team->slug}/automation/workflows", $payload);

        $duplicate->assertUnprocessable();
        $duplicate->assertJsonValidationErrors(['code']);

        $this->assertSame(1, AutomationWorkflow::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('code', 'wf_duplicated')
            ->count());
    }

    public function test_store_allows_same_workflow_code_for_another_team(): void
    {
        $other = User::factory()->create();

        AutomationWorkflow::factory()->create([
            'team_id' => $other->currentTeam->id,
            'code' => 'wf_shared_code',
        ]);

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($user);

        $this->postJson("/api/{$team->slug}/automation/workflows", [
            'code' => 'wf_shared_code',
            'name' => 'Mismo código, otro tenant',
            'trigger_type' => WorkflowTriggerType::IncidentCreated->value,
            'status' => WorkflowStatus::Active->value,
            'steps_json' => [[
                'order' => 1,
                'action_type' => ActionType::SendEmail->value,
                'execution_mode' => 'async',
            ]],
        ])->assertCreated();
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

    public function test_two_tenants_can_trigger_the_same_global_workflow_with_the_same_reference(): void
    {
        Bus::fake();

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $teamA = $userA->currentTeam;
        $teamB = $userB->currentTeam;

        // Workflow global de plataforma: lo comparten todos los tenants.
        $workflow = AutomationWorkflow::factory()->systemWide()->create();

        $this->actingAs($userA);
        $this->postJson(
            "/api/{$teamA->slug}/automation/workflows/{$workflow->id}/trigger",
            ['source_reference_id' => 'shared-ref'],
        )->assertStatus(202);

        // El tenant B no choca con la ejecución de A (antes: 500 por el
        // unique global) ni recibe un 409 que le revele que A ya lo corrió.
        $this->actingAs($userB);
        $responseB = $this->postJson(
            "/api/{$teamB->slug}/automation/workflows/{$workflow->id}/trigger",
            ['source_reference_id' => 'shared-ref'],
        );

        $responseB->assertStatus(202);
        $this->assertSame($teamB->id, $responseB->json('data.team_id'));

        foreach ([$teamA, $teamB] as $team) {
            $this->assertSame(1, WorkflowExecution::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('automation_workflow_id', $workflow->id)
                ->where('source_type', ActionExecutionSourceType::Manual->value)
                ->where('source_reference_id', 'shared-ref')
                ->count());
        }
    }

    public function test_same_tenant_triggering_a_global_workflow_twice_is_idempotent(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $workflow = AutomationWorkflow::factory()->systemWide()->create();

        $this->actingAs($user);

        $this->postJson(
            "/api/{$team->slug}/automation/workflows/{$workflow->id}/trigger",
            ['source_reference_id' => 'same-ref'],
        )->assertStatus(202);

        $this->postJson(
            "/api/{$team->slug}/automation/workflows/{$workflow->id}/trigger",
            ['source_reference_id' => 'same-ref'],
        )->assertStatus(409);

        $this->assertSame(1, WorkflowExecution::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('automation_workflow_id', $workflow->id)
            ->count());
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
