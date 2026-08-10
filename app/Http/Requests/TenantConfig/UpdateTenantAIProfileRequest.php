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
            // Tarea 12 (corrección post-revisión): risk_tolerance /
            // false_positive_tolerance / media_strategy ya no tienen control
            // en la UI (settings/tenant-config.tsx), así que el form dejó de
            // mandarlos. `sometimes` en vez de `required` para no romper el
            // guardado; TenantAIProfileController::update conserva el valor
            // persistido actual cuando la clave falta.
            'risk_tolerance' => ['sometimes', 'string', Rule::enum(RiskTolerance::class)],
            'false_positive_tolerance' => ['sometimes', 'string', Rule::enum(FalsePositiveTolerance::class)],
            'automation_level' => ['required', 'string', Rule::enum(AutomationLevel::class)],
            'media_strategy' => ['sometimes', 'string', Rule::enum(MediaStrategy::class)],
            'prompt_overrides' => ['nullable', 'array'],
            'human_review_policy' => ['nullable', 'array'],
        ];
    }
}
