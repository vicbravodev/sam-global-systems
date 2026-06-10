<?php

namespace App\Http\Requests\Automation;

use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Support\Conditions\ValidFlatConditions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationWorkflowRequest extends FormRequest
{
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'trigger_type' => ['sometimes', Rule::enum(WorkflowTriggerType::class)],
            'trigger_conditions_json' => ['nullable', 'array', new ValidFlatConditions],
            'status' => ['sometimes', Rule::enum(WorkflowStatus::class)],
            'version' => ['sometimes', 'integer', 'min:1'],
            'steps_json' => ['sometimes', 'array', 'min:1'],
            'steps_json.*.action_type' => ['required_with:steps_json', 'string'],
            'steps_json.*.execution_mode' => ['nullable', 'string'],
            'steps_json.*.delay_seconds' => ['nullable', 'integer', 'min:0'],
            'steps_json.*.order' => ['nullable', 'integer', 'min:1'],
            'steps_json.*.target_type' => ['nullable', 'string', 'max:100'],
            'steps_json.*.target_reference' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
