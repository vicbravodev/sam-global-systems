<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\TenantConfig\Enums\AutomationLevel;
use App\Domains\TenantConfig\Enums\FalsePositiveTolerance;
use App\Domains\TenantConfig\Enums\MediaStrategy;
use App\Domains\TenantConfig\Enums\RiskTolerance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantAIProfileRequest extends FormRequest
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
            'profile_code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'risk_tolerance' => ['required', 'string', Rule::enum(RiskTolerance::class)],
            'false_positive_tolerance' => ['required', 'string', Rule::enum(FalsePositiveTolerance::class)],
            'automation_level' => ['required', 'string', Rule::enum(AutomationLevel::class)],
            'media_strategy' => ['required', 'string', Rule::enum(MediaStrategy::class)],
            'prompt_overrides' => ['nullable', 'array'],
            'human_review_policy' => ['nullable', 'array'],
        ];
    }
}
