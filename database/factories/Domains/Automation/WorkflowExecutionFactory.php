<?php

namespace Database\Factories\Domains\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\WorkflowExecutionStatus;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\WorkflowExecution;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowExecution>
 */
class WorkflowExecutionFactory extends Factory
{
    protected $model = WorkflowExecution::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'automation_workflow_id' => AutomationWorkflow::factory(),
            'source_type' => ActionExecutionSourceType::Workflow->value,
            'source_reference_id' => (string) fake()->numberBetween(1, 1000),
            'status' => WorkflowExecutionStatus::Running,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function status(WorkflowExecutionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => WorkflowExecutionStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
