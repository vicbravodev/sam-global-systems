<?php

namespace App\Http\Requests\Automation;

use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
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
            'trigger_conditions_json' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::enum(WorkflowStatus::class)],
            'version' => ['sometimes', 'integer', 'min:1'],
            'steps_json' => ['sometimes', 'array', 'min:1'],
            'steps_json.*.action_type' => ['required_with:steps_json', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
