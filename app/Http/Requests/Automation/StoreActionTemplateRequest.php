<?php

namespace App\Http\Requests\Automation;

use App\Domains\Automation\Enums\ActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'action_type' => ['required', Rule::enum(ActionType::class)],
            'channel' => ['nullable', 'string', 'max:255'],
            'subject_template' => ['nullable', 'string'],
            'body_template' => ['nullable', 'string'],
            'parameters_schema_json' => ['nullable', 'array'],
            'config_json' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
