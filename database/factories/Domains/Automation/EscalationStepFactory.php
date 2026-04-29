<?php

namespace Database\Factories\Domains\Automation;

use App\Domains\Automation\Enums\EscalationStepType;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Automation\Models\EscalationStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EscalationStep>
 */
class EscalationStepFactory extends Factory
{
    protected $model = EscalationStep::class;

    public function definition(): array
    {
        return [
            'automation_workflow_id' => AutomationWorkflow::factory(),
            'step_order' => 1,
            'step_type' => EscalationStepType::Notify,
            'target_type' => 'role',
            'target_reference' => 'supervisor',
            'delay_seconds' => 0,
            'conditions_json' => null,
            'fallback_action' => null,
        ];
    }

    public function ofType(EscalationStepType $type): static
    {
        return $this->state(fn () => ['step_type' => $type]);
    }

    public function delayed(int $seconds): static
    {
        return $this->state(fn () => ['delay_seconds' => $seconds]);
    }
}
