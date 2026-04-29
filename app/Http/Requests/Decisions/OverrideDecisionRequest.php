<?php

namespace App\Http\Requests\Decisions;

use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OverrideDecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'new_outcome' => ['required', 'string', Rule::in(array_map(fn (DecisionOutcomeCode $c) => $c->value, DecisionOutcomeCode::cases()))],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
