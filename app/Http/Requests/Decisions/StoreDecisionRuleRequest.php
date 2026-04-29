<?php

namespace App\Http\Requests\Decisions;

use App\Domains\Decisions\Enums\RuleScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDecisionRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ruleset_id' => ['required', 'integer', 'exists:rule_sets,id'],
            'code' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'scope' => ['required', Rule::in(array_map(fn (RuleScope $s) => $s->value, RuleScope::cases()))],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
            'conditions_json' => ['required', 'array'],
            'outcome_override' => ['nullable', 'integer', 'exists:decision_outcomes,id'],
            'escalation_policy_id' => ['nullable', 'integer', 'exists:escalation_policies,id'],
            'stop_processing' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
