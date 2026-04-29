<?php

namespace Database\Factories\Domains\Automation;

use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionExecutionLog>
 */
class ActionExecutionLogFactory extends Factory
{
    protected $model = ActionExecutionLog::class;

    public function definition(): array
    {
        return [
            'action_execution_id' => ActionExecution::factory(),
            'log_type' => ActionLogType::Info,
            'message' => 'Action executed.',
            'payload_json' => null,
        ];
    }

    public function ofType(ActionLogType $type): static
    {
        return $this->state(fn () => ['log_type' => $type]);
    }
}
