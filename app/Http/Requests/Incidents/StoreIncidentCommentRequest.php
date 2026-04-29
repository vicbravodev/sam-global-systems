<?php

namespace App\Http\Requests\Incidents;

use App\Domains\Incidents\Enums\CommentVisibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentCommentRequest extends FormRequest
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
            'comment' => ['required', 'string'],
            'visibility' => ['nullable', 'string', Rule::in(array_column(CommentVisibility::cases(), 'value'))],
        ];
    }
}
