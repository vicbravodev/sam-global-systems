<?php

namespace App\Http\Requests\TenantConfig;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantEscalationConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'escalation_type' => ['sometimes', 'string', 'max:255'],
            'trigger_conditions' => ['sometimes', 'array'],
            'steps' => ['sometimes', 'array', 'min:1'],
            'time_constraints' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
