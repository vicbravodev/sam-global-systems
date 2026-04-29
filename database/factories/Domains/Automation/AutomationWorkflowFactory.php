<?php

namespace Database\Factories\Domains\Automation;

use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationWorkflow>
 */
class AutomationWorkflowFactory extends Factory
{
    protected $model = AutomationWorkflow::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'code' => 'wf_'.fake()->unique()->lexify('????'),
            'name' => 'Sample Workflow',
            'description' => 'Generated workflow used in tests.',
            'trigger_type' => WorkflowTriggerType::IncidentCreated,
            'trigger_conditions_json' => [],
            'status' => WorkflowStatus::Active,
            'version' => 1,
            'steps_json' => [
                [
                    'order' => 1,
                    'action_type' => ActionType::SendEmail->value,
                    'template_code' => null,
                    'execution_mode' => 'async',
                    'delay_seconds' => 0,
                    'target_type' => 'role',
                    'target_reference' => 'tenant_admin',
                ],
            ],
            'is_active' => true,
        ];
    }

    public function systemWide(): static
    {
        return $this->state(fn () => ['team_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => WorkflowStatus::Inactive,
            'is_active' => false,
        ]);
    }

    public function trigger(WorkflowTriggerType $type): static
    {
        return $this->state(fn () => ['trigger_type' => $type]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    public function withSteps(array $steps): static
    {
        return $this->state(fn () => ['steps_json' => $steps]);
    }
}
