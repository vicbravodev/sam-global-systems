<?php

namespace App\Http\Requests\Integrations;

use App\Domains\Integrations\Enums\AuthType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntegrationRequest extends FormRequest
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
            'provider_id' => ['required', 'integer', 'exists:integration_providers,id'],
            'name' => ['required', 'string', 'max:255'],
            'auth_type' => ['required', 'string', Rule::enum(AuthType::class)],
            'credentials' => ['required', 'string'],
            'config' => ['nullable', 'array'],
        ];
    }
}
