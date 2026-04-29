<?php

namespace Database\Factories\Domains\Automation;

use App\Domains\Automation\Enums\ActionExecutionSourceType;
use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\ExecutionMode;
use App\Domains\Automation\Models\ActionExecution;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionExecution>
 */
class ActionExecutionFactory extends Factory
{
    protected $model = ActionExecution::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'action_type' => ActionType::SendEmail,
            'source_type' => ActionExecutionSourceType::Workflow,
            'source_reference_id' => (string) fake()->numberBetween(1, 1000),
            'incident_id' => null,
            'decision_id' => null,
            'automation_workflow_id' => null,
            'action_template_id' => null,
            'status' => ActionExecutionStatus::Pending,
            'execution_mode' => ExecutionMode::Async,
            'target_type' => 'role',
            'target_reference' => 'tenant_admin',
            'payload_json' => ['summary' => 'baseline'],
            'response_json' => null,
            'error_message' => null,
            'attempts' => 0,
            'executed_at' => null,
        ];
    }

    public function status(ActionExecutionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function fromIncident(int $incidentId): static
    {
        return $this->state(fn () => [
            'source_type' => ActionExecutionSourceType::Incident,
            'incident_id' => $incidentId,
            'source_reference_id' => (string) $incidentId,
        ]);
    }

    public function requiresConfirmation(): static
    {
        return $this->state(fn () => [
            'execution_mode' => ExecutionMode::RequiresConfirmation,
            'status' => ActionExecutionStatus::Pending,
        ]);
    }

    public function failed(string $message = 'Test failure'): static
    {
        return $this->state(fn () => [
            'status' => ActionExecutionStatus::Failed,
            'error_message' => $message,
            'attempts' => 3,
        ]);
    }
}
