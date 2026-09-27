<?php

namespace App\Http\Requests\Decisions;

use App\Domains\Decisions\Enums\RuleScope;
use App\Models\Team;
use App\Support\Conditions\ValidConditionTree;
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
            'conditions_json' => ['sometimes', 'array', new ValidConditionTree],
            'outcome_override' => ['sometimes', 'nullable', 'integer', 'exists:decision_outcomes,id'],
            // La política de escalamiento debe ser del mismo tenant.
            'escalation_policy_id' => ['sometimes', 'nullable', 'integer', Rule::exists('escalation_policies', 'id')->where('team_id', $this->currentTeamId())],
            'stop_processing' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
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
