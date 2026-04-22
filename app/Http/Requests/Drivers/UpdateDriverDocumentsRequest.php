<?php

namespace App\Http\Requests\Drivers;

use App\Domains\Drivers\Enums\DocumentStatus;
use App\Domains\Drivers\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDriverDocumentsRequest extends FormRequest
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
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.document_type' => ['required', 'string', Rule::enum(DocumentType::class)],
            'documents.*.document_number' => ['nullable', 'string', 'max:255'],
            'documents.*.issued_at' => ['nullable', 'date'],
            'documents.*.expires_at' => ['nullable', 'date'],
            'documents.*.status' => ['sometimes', 'string', Rule::enum(DocumentStatus::class)],
            'documents.*.file_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
