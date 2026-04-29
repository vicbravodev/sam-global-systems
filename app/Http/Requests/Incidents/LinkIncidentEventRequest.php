<?php

namespace App\Http\Requests\Incidents;

use App\Domains\Incidents\Enums\EventRelationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkIncidentEventRequest extends FormRequest
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
            'normalized_event_id' => ['required', 'integer', 'exists:normalized_events,id'],
            'relation_type' => ['required', 'string', Rule::in(array_column(EventRelationType::cases(), 'value'))],
        ];
    }
}
