<?php

namespace App\Http\Requests\Decisions;

use App\Support\Conditions\ValidFlatConditions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEscalationPolicyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'trigger_conditions_json' => ['sometimes', 'nullable', 'array', new ValidFlatConditions],
            'escalation_steps_json' => ['sometimes', 'array', 'min:1'],
            'max_wait_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'requires_acknowledgement' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
