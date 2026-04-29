<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\TenantConfig\Enums\RuleOverrideType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRuleOverrideRequest extends FormRequest
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
            'override_type' => ['sometimes', 'string', Rule::enum(RuleOverrideType::class)],
            'override_config' => ['sometimes', 'array'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
