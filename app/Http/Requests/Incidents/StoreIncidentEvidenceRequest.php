<?php

namespace App\Http\Requests\Incidents;

use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentEvidenceRequest extends FormRequest
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
            'evidence_type' => ['required', 'string', Rule::in(array_column(EvidenceType::cases(), 'value'))],
            'source_type' => ['required', 'string', Rule::in(array_column(EvidenceSourceType::cases(), 'value'))],
            'source_reference_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'file' => ['nullable', 'file', 'max:51200'],
        ];
    }
}
