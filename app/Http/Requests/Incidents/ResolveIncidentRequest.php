<?php

namespace App\Http\Requests\Incidents;

use App\Domains\Incidents\Enums\ResolutionCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveIncidentRequest extends FormRequest
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
            'resolution_code' => ['required', 'string', Rule::in(array_column(ResolutionCode::cases(), 'value'))],
            'summary' => ['required', 'string'],
            'root_cause' => ['nullable', 'string'],
            'corrective_action' => ['nullable', 'string'],
            'preventive_action' => ['nullable', 'string'],
        ];
    }
}
