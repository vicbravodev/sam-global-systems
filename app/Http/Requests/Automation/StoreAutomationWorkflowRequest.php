<?php

namespace App\Http\Requests\Automation;

use App\Domains\Automation\Enums\WorkflowStatus;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Support\Conditions\ValidFlatConditions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAutomationWorkflowRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:255'],
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
            'steps_json.*.target_reference' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
