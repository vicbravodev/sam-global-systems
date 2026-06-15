<?php

namespace App\Http\Requests\Decisions;

use App\Domains\Decisions\Enums\RuleScope;
use App\Models\Team;
use App\Support\Conditions\ValidConditionTree;
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
            'code' => [
                'required',
                'string',
                'max:120',
                // D-01: un doble submit no debe crear reglas duplicadas — el
                // código es único por tenant (las reglas globales no bloquean
                // códigos del tenant).
                Rule::unique('decision_rules', 'code')->where('team_id', $this->currentTeamId()),
            ],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'scope' => ['required', Rule::in(array_map(fn (RuleScope $s) => $s->value, RuleScope::cases()))],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
            'conditions_json' => ['required', 'array', new ValidConditionTree],
            'outcome_override' => ['nullable', 'integer', 'exists:decision_outcomes,id'],
            'escalation_policy_id' => ['nullable', 'integer', 'exists:escalation_policies,id'],
            'stop_processing' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya existe una regla con este código en tu equipo.',
        ];
    }

    private function currentTeamId(): ?int
    {
        $team = $this->route('current_team');

        if ($team instanceof Team) {
            return $team->id;
        }

        return is_string($team)
            ? Team::query()->where('slug', $team)->value('id')
            : null;
    }
}
