<?php

namespace App\Http\Requests\Copilot;

use App\Domains\Copilot\Enums\CopilotIntent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCopilotMessageRequest extends FormRequest
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
            'content' => ['required', 'string', 'min:2', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'asset_id' => ['nullable', 'integer'],
            'intent' => ['nullable', Rule::enum(CopilotIntent::class)],
            'channel' => ['nullable', Rule::in(['page', 'bubble'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Escribe una pregunta.',
            'content.max' => 'La pregunta no puede superar los 2000 caracteres.',
        ];
    }
}
