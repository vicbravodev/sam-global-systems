<?php

namespace App\Http\Requests\TenantConfig;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantScheduleProfileRequest extends FormRequest
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
            'profile_code' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:100'],
            'operating_hours' => ['sometimes', 'array'],
            'holidays' => ['nullable', 'array'],
            'shift_rules' => ['nullable', 'array'],
            'after_hours_behavior' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
