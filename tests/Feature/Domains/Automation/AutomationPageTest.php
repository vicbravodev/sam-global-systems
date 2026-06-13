<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap F12: the automation page (workflows + executions) and its web
 * mutations reusing the Automation API controllers.
 */
class AutomationPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_page_renders_workflows_and_executions(): void
    {
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
            ->create(['team_id' => $this->team->id]);

        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'status' => ActionExecutionStatus::Failed,
            'error_message' => 'Twilio timeout',
        ]);

        $response = $this->actingAs($this->user)->get(
            route('automation.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('automation/index')
                ->has('workflows', 1)
                ->has('workflows.0.steps', 1)
                ->has('executions', 1)
                ->where('executions.0.status', 'failed')
                ->has('options.actionTypes')
                ->has('options.triggerTypes')
                ->has('triggerConditionFields.decision_outcome', 3)
                ->has('triggerConditionFields.incident_created', 2)
                ->where('triggerConditionFields.incident_created.0.key', 'incident_type')
                ->where('canManage', true),
        );
    }

    public function test_page_hides_other_tenant_workflows(): void
    {
        AutomationWorkflow::factory()->create([
            'team_id' => User::factory()->create()->currentTeam->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('automation.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page->has('workflows', 0)->has('executions', 0),
        );
    }

    public function test_workflow_can_be_created_via_web_route(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            route('automation.workflows.store', ['current_team' => $this->team->slug]),
            [
                'code' => 'critico-notifica',
                'name' => 'Crítico → notificar admins',
                'trigger_type' => 'incident_created',
                'status' => 'active',
                'steps_json' => [
                    [
                        'order' => 1,
                        'action_type' => 'send_email',
                        'execution_mode' => 'async',
                        'target_type' => 'role',
                        'target_reference' => 'tenant_admin',
                        'delay_seconds' => 0,
                    ],
                ],
                'is_active' => true,
            ],
        );

        $response->assertCreated();

        $workflow = AutomationWorkflow::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('code', 'critico-notifica')
            ->first();

        $this->assertNotNull($workflow);

        // The step target must survive validation — B7 executors resolve the
        // notification recipient from it.
        $this->assertSame('role', $workflow->steps_json[0]['target_type'] ?? null);
        $this->assertSame('tenant_admin', $workflow->steps_json[0]['target_reference'] ?? null);
    }

    public function test_failed_execution_can_be_retried_via_web_route(): void
    {
        $execution = ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'status' => ActionExecutionStatus::Failed,
            'attempts' => 1,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('automation.executions.retry', [
                'current_team' => $this->team->slug,
                'execution' => $execution->id,
            ]),
        );

        $response->assertStatus(202);
    }

    public function test_pending_execution_can_be_cancelled_via_web_route(): void
    {
        $execution = ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'status' => ActionExecutionStatus::Pending,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('automation.executions.cancel', [
                'current_team' => $this->team->slug,
                'execution' => $execution->id,
            ]),
        );

        $response->assertOk();
        $this->assertSame(
            ActionExecutionStatus::Cancelled,
            $execution->fresh()->status,
        );
    }

    public function test_workflow_can_be_deleted_via_web_route(): void
    {
        // D-09: un workflow ya no es eterno — se puede eliminar.
        $workflow = AutomationWorkflow::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson(
            route('automation.workflows.destroy', [
                'current_team' => $this->team->slug,
                'workflow' => $workflow->id,
            ]),
        );

        $response->assertNoContent();
        $this->assertDatabaseMissing('automation_workflows', [
            'id' => $workflow->id,
        ]);
    }

    public function test_workflow_metadata_can_be_edited_via_web_route(): void
    {
        // D-09: editar nombre/descripción y activar/desactivar.
        $workflow = AutomationWorkflow::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Nombre viejo',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->putJson(
            route('automation.workflows.update', [
                'current_team' => $this->team->slug,
                'workflow' => $workflow->id,
            ]),
            [
                'name' => 'Nombre nuevo',
                'description' => 'Descripción editada',
                'is_active' => false,
            ],
        );

        $response->assertOk();
        $fresh = $workflow->fresh();
        $this->assertSame('Nombre nuevo', $fresh->name);
        $this->assertSame('Descripción editada', $fresh->description);
        $this->assertFalse((bool) $fresh->is_active);
    }

    public function test_member_without_automation_manage_cannot_delete_workflow(): void
    {
        // Viewer (sin automation.manage) sobre un workflow de su propio team:
        // la negativa proviene de la policy (403), no de aislamiento de tenant.
        [$viewer, $viewerTeam] = $this->createUserWithRole('auto_viewer', ['automation.view']);

        $workflow = AutomationWorkflow::factory()->create([
            'team_id' => $viewerTeam->id,
        ]);

        $this->actingAs($viewer)->deleteJson(
            route('automation.workflows.destroy', [
                'current_team' => $viewerTeam->slug,
                'workflow' => $workflow->id,
            ]),
        )->assertForbidden();

        $this->assertDatabaseHas('automation_workflows', ['id' => $workflow->id]);
    }

    public function test_non_member_cannot_delete_workflow(): void
    {
        $workflow = AutomationWorkflow::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->deleteJson(
            route('automation.workflows.destroy', [
                'current_team' => $this->team->slug,
                'workflow' => $workflow->id,
            ]),
        )->assertForbidden();
    }

    /**
     * @param  array<int, string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function createUserWithRole(string $roleCode, array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $role = Role::factory()->create([
            'code' => $roleCode,
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                [
                    'name' => ucfirst(str_replace('.', ' ', $code)),
                    'module' => explode('.', $code, 2)[0],
                ],
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $team->members()->updateExistingPivot($user->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }
}
