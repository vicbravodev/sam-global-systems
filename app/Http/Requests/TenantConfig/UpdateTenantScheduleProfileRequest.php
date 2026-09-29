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
     * `shift_rules.on_call` is read by ResolveOnCallOperator inside the
     * incident-creation transaction, so its shape is enforced here: each
     * shift is an object with an integer `user_id`, optional `days` (English
     * day names, any case, as the evaluator lowercases them) and optional
     * `start`/`end` in H:i. Other shapes of `shift_rules` (e.g. the legacy list
     * of unnamed shifts) carry no `on_call` key and are stored as-is.
     *
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
            'shift_rules.on_call' => ['sometimes', 'nullable', 'array'],
            'shift_rules.on_call.*' => ['array'],
            'shift_rules.on_call.*.user_id' => ['required', 'integer'],
            'shift_rules.on_call.*.days' => ['nullable', 'array'],
            'shift_rules.on_call.*.days.*' => ['string', 'regex:/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i'],
            'shift_rules.on_call.*.start' => ['nullable', 'string', 'date_format:H:i'],
            'shift_rules.on_call.*.end' => ['nullable', 'string', 'date_format:H:i'],
            'shift_rules.fallback_on_call_user_id' => ['nullable', 'integer'],
            'after_hours_behavior' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
