<?php

namespace App\Http\Requests\Automation;

use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Http\Requests\Concerns\ValidatesWorkflowStepTargets;
use App\Support\Conditions\ValidFlatConditions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationWorkflowRequest extends FormRequest
{
    use ValidatesWorkflowStepTargets;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:255',
                // D-02: un doble submit no debe crear workflows duplicados —
                // el código es único por tenant.
                Rule::unique('automation_workflows', 'code')->where('team_id', $this->currentTeamId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['required', Rule::enum(WorkflowTriggerType::class)],
            'trigger_conditions_json' => ['nullable', 'array', new ValidFlatConditions],
            'status' => ['required', Rule::enum(WorkflowStatus::class)],
            'version' => ['nullable', 'integer', 'min:1'],
            'steps_json' => ['required', 'array', 'min:1'],
            'steps_json.*.action_type' => ['required', 'string'],
            'steps_json.*.execution_mode' => ['nullable', 'string'],
            'steps_json.*.delay_seconds' => ['nullable', 'integer', 'min:0'],
            'steps_json.*.order' => ['nullable', 'integer', 'min:1'],
            'steps_json.*.target_type' => ['nullable', 'string', 'max:100'],
            'steps_json.*.target_reference' => ['nullable', 'string', 'max:255', $this->stepTargetRule()],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe un workflow con este código en tu equipo.',
        ];
    }
}
