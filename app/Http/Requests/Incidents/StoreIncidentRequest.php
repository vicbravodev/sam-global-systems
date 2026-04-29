<?php

namespace App\Http\Requests\Incidents;

use Illuminate\Foundation\Http\FormRequest;

class StoreIncidentRequest extends FormRequest
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
            'asset_id' => ['nullable', 'integer'],
            'driver_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
