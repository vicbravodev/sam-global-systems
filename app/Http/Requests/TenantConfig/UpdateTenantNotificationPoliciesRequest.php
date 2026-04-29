<?php

namespace App\Http\Requests\TenantConfig;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantNotificationPoliciesRequest extends FormRequest
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
            'policies' => ['required', 'array', 'min:1'],
            'policies.*.policy_code' => ['required', 'string', 'max:255'],
            'policies.*.notification_type' => ['nullable', 'string', 'max:255'],
            'policies.*.priority' => ['nullable', 'string', 'max:50'],
            'policies.*.allowed_channels' => ['required', 'array', 'min:1'],
            'policies.*.allowed_channels.*' => ['string', 'max:50'],
            'policies.*.fallback_channels' => ['nullable', 'array'],
            'policies.*.fallback_channels.*' => ['string', 'max:50'],
            'policies.*.recipient_rules' => ['nullable', 'array'],
            'policies.*.quiet_hours' => ['nullable', 'array'],
            'policies.*.escalation_rules' => ['nullable', 'array'],
            'policies.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
