<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\TenantConfig\Enums\RuleOverrideType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRuleOverrideRequest extends FormRequest
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
            'base_rule_code' => ['required', 'string', 'max:255'],
            'override_type' => ['required', 'string', Rule::enum(RuleOverrideType::class)],
            'override_config' => ['required', 'array'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
