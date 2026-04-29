<?php

namespace App\Http\Requests\Incidents;

use App\Domains\Incidents\Enums\AssigneeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignIncidentRequest extends FormRequest
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
            'assigned_to_type' => ['required', 'string', Rule::in(array_column(AssigneeType::cases(), 'value'))],
            'assigned_to_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:120'],
        ];
    }
}
