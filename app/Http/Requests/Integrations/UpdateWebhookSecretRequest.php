<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Secret Key que Samsara genera al crear el webhook (Base64). La autorización
 * la hace el controlador con la Policy de la integración.
 */
class UpdateWebhookSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('webhook_secret'))) {
            $this->merge(['webhook_secret' => trim($this->input('webhook_secret'))]);
        }
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'webhook_secret' => ['required', 'string', 'min:16', 'max:512', 'not_regex:/\s/'],
        ];
    }
}
