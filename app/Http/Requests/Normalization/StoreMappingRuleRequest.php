<?php

namespace App\Http\Requests\Normalization;

use App\Domains\Normalization\Models\EventMappingRule;
use App\Support\Conditions\ValidFlatConditions;
use Illuminate\Foundation\Http\FormRequest;

class StoreMappingRuleRequest extends FormRequest
{
    /**
     * Reglas globales de plataforma: sólo el super-admin las muta.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', EventMappingRule::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'exists:integration_providers,id'],
            'external_event_type' => ['required', 'string', 'max:255'],
            'external_conditions_json' => ['nullable', 'array', new ValidFlatConditions],
            'mapped_event_type_id' => ['required', 'exists:event_types,id'],
            'mapped_category_id' => ['nullable', 'exists:event_categories,id'],
            'mapped_severity_id' => ['nullable', 'exists:event_severities,id'],
            'priority' => ['integer', 'min:0', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
