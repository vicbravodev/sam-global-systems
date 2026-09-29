<?php

namespace Tests\Feature\Domains\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Jobs\RunAutomationWorkflowJob;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Models\Team;
use Database\Seeders\AutomationMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * CLAUDE.md §2.1 punto 5: un job que recibe a la vez un id de recurso y un
 * `teamId` debe validar que concuerdan y abortar si no.
 *
 * `AutomationWorkflow` no lleva el trait (puede ser global, `team_id` null), así
 * que el scope global no protege el lookup: sin esta guarda, un id de workflow
 * de otro tenant ejecutaría sus pasos a nombre de `teamId` y se lo cobraría.
 */
class RunAutomationWorkflowJobTenantGuardTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AutomationMeterSeeder::class);
    }

    public function test_it_does_not_run_another_tenants_workflow(): void
    {
        $owner = Team::factory()->create();
        $intruder = Team::factory()->create();

        $foreignWorkflow = AutomationWorkflow::factory()->create(['team_id' => $owner->id]);

        RunAutomationWorkflowJob::dispatchSync(
            $foreignWorkflow->id,
            $intruder->id,
            ActionExecutionSourceType::Manual->value,
            null,
        );

        $this->assertSame(0, WorkflowExecution::withoutGlobalScopes()->count());

        $this->assertSystemLogged('automation.workflow.skipped', fn (array $c) => $c['reason'] === 'workflow_unavailable'
            && $c['input'] === ['source_type' => ActionExecutionSourceType::Manual->value]);
        $this->assertStringNotContainsString('"automation_workflow_id"', json_encode($this->systemLogEntries()));
        $this->assertStringNotContainsString((string) $foreignWorkflow->id, json_encode(array_column($this->systemLogEntries('automation.workflow.skipped'), 'context')));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_runs_the_tenants_own_workflow(): void
    {
        $team = Team::factory()->create();
        $workflow = AutomationWorkflow::factory()->create(['team_id' => $team->id]);

        RunAutomationWorkflowJob::dispatchSync(
            $workflow->id,
            $team->id,
            ActionExecutionSourceType::Manual->value,
            null,
        );

        $this->assertSame(1, WorkflowExecution::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public function test_it_runs_a_platform_workflow_for_any_tenant(): void
    {
        $team = Team::factory()->create();
        $platformWorkflow = AutomationWorkflow::factory()->create(['team_id' => null]);

        RunAutomationWorkflowJob::dispatchSync(
            $platformWorkflow->id,
            $team->id,
            ActionExecutionSourceType::Manual->value,
            null,
        );

        $this->assertSame(1, WorkflowExecution::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }
}
