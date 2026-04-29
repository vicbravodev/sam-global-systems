<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

class ReclassifyIncidentRequest extends FormRequest
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
            'incident_type_id' => ['required', 'integer', 'exists:incident_types,id'],
            'incident_priority_id' => ['nullable', 'integer', 'exists:incident_priorities,id'],
        ];
    }
}
