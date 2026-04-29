<?php

namespace App\Http\Requests\Decisions;

use App\Domains\Decisions\Enums\RuleScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDecisionRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string'],
            'scope' => ['sometimes', Rule::in(array_map(fn (RuleScope $s) => $s->value, RuleScope::cases()))],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'conditions_json' => ['sometimes', 'array'],
            'outcome_override' => ['sometimes', 'nullable', 'integer', 'exists:decision_outcomes,id'],
            'escalation_policy_id' => ['sometimes', 'nullable', 'integer', 'exists:escalation_policies,id'],
            'stop_processing' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
