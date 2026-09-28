<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Domains\Automation\Queries\WorkflowRunStats;
use App\Domains\Incidents\Models\Incident;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Roadmap F12: the automation page (workflows + executions) and its web
 * mutations reusing the Automation API controllers.
 */
class AutomationPageTest extends TestCase
{
    use AssertsTenantIsolation;
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
                ->where('executions.0.statusLabel', 'Fallida')
                ->where('executions.0.targetLabel', 'Rol: Administrador del tenant')
                ->where('workflows.0.stepTargets', ['Rol: Administrador del tenant'])
                ->has('options.actionTypes')
                ->has('options.triggerTypes')
                ->where('options.actionTypes.0', ['value' => 'send_email', 'label' => 'Enviar correo'])
                ->where('options.triggerTypes.0', ['value' => 'decision_outcome', 'label' => 'Resultado de decisión'])
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

    public function test_steps_show_human_target_labels(): void
    {
        $operator = User::factory()->create(['name' => 'Ana Operadora']);
        $this->team->members()->attach($operator, ['role' => TeamRole::Member->value]);
        $outsider = User::factory()->create(['name' => 'Persona Ajena']);

        AutomationWorkflow::factory()
            ->withSteps([
                ['order' => 1, 'action_type' => 'send_sms', 'target_type' => 'role', 'target_reference' => 'admin'],
                ['order' => 2, 'action_type' => 'assign_incident', 'target_type' => 'user', 'target_reference' => (string) $operator->id],
                ['order' => 3, 'action_type' => 'create_ticket', 'target_type' => 'external', 'target_reference' => 'mesa-de-ayuda'],
                // Un id de usuario de otro tenant nunca revela su nombre.
                ['order' => 4, 'action_type' => 'send_email', 'target_type' => 'user', 'target_reference' => (string) $outsider->id, 'delay_seconds' => 300],
            ])
            ->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->get(route('automation.show', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workflows.0.stepTargets', [
                    'Rol: Administrador',
                    'Ana Operadora',
                    'Contacto externo: Mesa de ayuda',
                    'Usuario que ya no es miembro',
                ]));
    }

    public function test_page_exposes_last_run_stats_per_workflow(): void
    {
        $workflow = AutomationWorkflow::factory()->create(['team_id' => $this->team->id]);
        $idle = AutomationWorkflow::factory()->create(['team_id' => $this->team->id]);

        // Fuera de la ventana de 30 días: no entra en los conteos.
        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'automation_workflow_id' => $workflow->id,
            'status' => ActionExecutionStatus::Failed,
            'created_at' => now()->subDays(45),
        ]);
        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'automation_workflow_id' => $workflow->id,
            'status' => ActionExecutionStatus::Failed,
            'created_at' => now()->subDays(2),
        ]);
        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'automation_workflow_id' => $workflow->id,
            'status' => ActionExecutionStatus::Completed,
            'executed_at' => now()->subHour(),
        ]);

        $this->actingAs($this->user)
            ->get(route('automation.show', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where("runStats.{$workflow->id}.lastStatus", 'completed')
                ->where("runStats.{$workflow->id}.runs30d", 2)
                ->where("runStats.{$workflow->id}.failed30d", 1)
                ->missing("runStats.{$idle->id}"));
    }

    public function test_run_stats_do_not_leak_other_tenant_executions(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        $otherWorkflow = AutomationWorkflow::factory()->create(['team_id' => $otherTeam->id]);
        ActionExecution::factory()->count(3)->create([
            'team_id' => $otherTeam->id,
            'automation_workflow_id' => $otherWorkflow->id,
        ]);

        $workflow = AutomationWorkflow::factory()->create(['team_id' => $this->team->id]);
        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'automation_workflow_id' => $workflow->id,
        ]);

        $stats = $this->assertNoTenantLeak(
            $this->team,
            fn () => app(WorkflowRunStats::class)->forTeam($this->team->id),
        );

        $this->assertSame([$workflow->id], array_keys($stats));
        $this->assertSame(1, $stats[$workflow->id]['runs30d']);
    }

    public function test_summary_counts_workflows_and_executions_of_the_window(): void
    {
        AutomationWorkflow::factory()->create(['team_id' => $this->team->id, 'is_active' => true, 'status' => 'active']);
        // is_active sin status activo no corre (scope `active()`): cuenta como inactiva.
        AutomationWorkflow::factory()->create(['team_id' => $this->team->id, 'is_active' => true, 'status' => 'draft']);
        AutomationWorkflow::factory()->inactive()->create(['team_id' => $this->team->id]);

        ActionExecution::factory()->failed()->create(['team_id' => $this->team->id]);
        ActionExecution::factory()->status(ActionExecutionStatus::Completed)->count(2)->create(['team_id' => $this->team->id]);
        ActionExecution::factory()->status(ActionExecutionStatus::Retrying)->create(['team_id' => $this->team->id]);
        // Fuera de la ventana: no cuenta…
        ActionExecution::factory()->failed()->create(['team_id' => $this->team->id, 'created_at' => now()->subDays(40)]);
        // …salvo que siga esperando confirmación.
        ActionExecution::factory()->requiresConfirmation()->create(['team_id' => $this->team->id, 'created_at' => now()->subDays(40)]);

        // Otro tenant nunca suma.
        $other = User::factory()->create()->currentTeam;
        AutomationWorkflow::factory()->create(['team_id' => $other->id]);
        ActionExecution::factory()->failed()->count(3)->create(['team_id' => $other->id]);

        $this->actingAs($this->user)
            ->get(route('automation.show', ['current_team' => $this->team->slug, 'execution_status' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('automation/index')
                ->where('summary.workflows', ['total' => 3, 'active' => 1, 'inactive' => 2])
                ->where('summary.executions.total', 5)
                ->where('summary.executions.failed', 1)
                ->where('summary.executions.pending', 1)
                ->where('summary.executions.completed', 2)
                ->where('summary.executions.in_progress', 1)
                ->where('summary.executions.cancelled', 0)
                // El filtro activo acota la lista, no el resumen.
                ->where('executionFilters.status', 'failed')
                ->has('executions', 1)
                ->where('executions.0.status', 'failed'));
    }

    public function test_executions_can_be_filtered_by_automation(): void
    {
        $workflow = AutomationWorkflow::factory()->create(['team_id' => $this->team->id, 'name' => 'Aviso de pánico']);
        $other = AutomationWorkflow::factory()->create(['team_id' => $this->team->id]);

        ActionExecution::factory()->create(['team_id' => $this->team->id, 'automation_workflow_id' => $workflow->id]);
        ActionExecution::factory()->create(['team_id' => $this->team->id, 'automation_workflow_id' => $other->id]);

        $this->actingAs($this->user)
            ->get(route('automation.show', ['current_team' => $this->team->slug, 'execution_workflow' => $workflow->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('executionFilters.workflow', $workflow->id)
                ->has('executions', 1)
                ->where('executions.0.workflowId', $workflow->id)
                ->where('executions.0.workflowName', 'Aviso de pánico'));
    }

    public function test_executions_resolve_the_incident_of_the_workflow_run(): void
    {
        $workflow = AutomationWorkflow::factory()->create(['team_id' => $this->team->id]);
        $incident = Incident::factory()->create(['team_id' => $this->team->id, 'title' => 'Botón de pánico']);

        $run = WorkflowExecution::factory()->create([
            'team_id' => $this->team->id,
            'automation_workflow_id' => $workflow->id,
            'source_type' => ActionExecutionSourceType::Incident->value,
            'source_reference_id' => (string) $incident->id,
        ]);

        // Paso creado antes del arreglo: sin incident_id, sólo el run.
        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'automation_workflow_id' => $workflow->id,
            'source_type' => ActionExecutionSourceType::Workflow,
            'source_reference_id' => (string) $run->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('automation.show', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('executions.0.incidentId', $incident->id)
                ->where('executions.0.incidentReference', $incident->reference())
                ->where('executions.0.incidentTitle', 'Botón de pánico'));
    }

    public function test_executions_never_reveal_another_tenant_incident_or_automation(): void
    {
        $other = User::factory()->create()->currentTeam;
        $foreignWorkflow = AutomationWorkflow::factory()->create(['team_id' => $other->id, 'name' => 'Ajena']);
        $foreignIncident = Incident::factory()->create(['team_id' => $other->id, 'title' => 'Incidente ajeno']);
        $foreignRun = WorkflowExecution::factory()->create([
            'team_id' => $other->id,
            'automation_workflow_id' => $foreignWorkflow->id,
            'source_type' => ActionExecutionSourceType::Incident->value,
            'source_reference_id' => (string) $foreignIncident->id,
        ]);

        // Filas propias que apuntan (por corrupción o ids adivinados) a datos ajenos.
        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'automation_workflow_id' => $foreignWorkflow->id,
            'source_type' => ActionExecutionSourceType::Workflow,
            'source_reference_id' => (string) $foreignRun->id,
        ]);
        ActionExecution::factory()->create([
            'team_id' => $this->team->id,
            'incident_id' => $foreignIncident->id,
        ]);
        ActionExecution::factory()->failed()->create(['team_id' => $other->id]);

        $response = $this->assertNoTenantLeak($this->team, fn () => $this->actingAs($this->user)->get(
            route('automation.show', ['current_team' => $this->team->slug, 'execution_workflow' => $foreignWorkflow->id]),
        ));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            // Un id de automatización ajeno en el filtro se ignora.
            ->where('executionFilters.workflow', null)
            ->has('executions', 2)
            ->where('executions.0.incidentId', null)
            ->where('executions.0.incidentReference', null)
            ->where('executions.1.incidentId', null)
            ->where('executions.1.workflowName', null)
            ->where('summary.executions.total', 2)
            ->where('summary.workflows.total', 0));
    }

    public function test_steps_expose_bare_recipient_names(): void
    {
        AutomationWorkflow::factory()
            ->withSteps([
                ['order' => 1, 'action_type' => 'send_whatsapp', 'target_type' => 'role', 'target_reference' => 'admin'],
                ['order' => 2, 'action_type' => 'escalate'],
                ['order' => 3, 'action_type' => 'send_email', 'target_type' => 'email', 'target_reference' => 'ops@cliente.mx'],
            ])
            ->create(['team_id' => $this->team->id]);

        $this->actingAs($this->user)
            ->get(route('automation.show', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('workflows.0.stepRecipients', ['Administrador', null, 'ops@cliente.mx'])
                ->where('workflows.0.stepTargets', ['Rol: Administrador', '—', 'Correo: ops@cliente.mx']));
    }
}
