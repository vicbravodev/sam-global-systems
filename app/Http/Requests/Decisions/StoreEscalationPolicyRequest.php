<?php

namespace App\Http\Requests\Decisions;

use Illuminate\Foundation\Http\FormRequest;

class StoreEscalationPolicyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'trigger_conditions_json' => ['nullable', 'array'],
            'escalation_steps_json' => ['required', 'array', 'min:1'],
            'max_wait_seconds' => ['nullable', 'integer', 'min:0'],
            'requires_acknowledgement' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
