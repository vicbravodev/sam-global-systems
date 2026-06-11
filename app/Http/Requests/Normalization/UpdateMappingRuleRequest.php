<?php

namespace App\Http\Requests\Normalization;

use App\Support\Conditions\ValidFlatConditions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMappingRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'external_event_type' => ['sometimes', 'string', 'max:255'],
            'external_conditions_json' => ['nullable', 'array', new ValidFlatConditions],
            'mapped_event_type_id' => ['sometimes', 'exists:event_types,id'],
            'mapped_category_id' => ['nullable', 'exists:event_categories,id'],
            'mapped_severity_id' => ['nullable', 'exists:event_severities,id'],
            'priority' => ['integer', 'min:0', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
