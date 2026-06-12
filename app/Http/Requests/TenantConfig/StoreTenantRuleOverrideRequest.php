<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\TenantConfig\Enums\RuleOverrideType;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRuleOverrideRequest extends FormRequest
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
        $teamId = $this->currentTeamId();

        return [
            'base_rule_code' => [
                'required',
                'string',
                'max:255',
                // D-06: el override debe apuntar a una regla de decisión real
                // (del tenant o global), no a un código inexistente.
                Rule::exists('decision_rules', 'code')->where(
                    fn ($query) => $query->where(
                        fn ($inner) => $inner->where('team_id', $teamId)->orWhereNull('team_id'),
                    ),
                ),
            ],
            'override_type' => ['required', 'string', Rule::enum(RuleOverrideType::class)],
            'override_config' => ['required', 'array'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_rule_code.exists' => 'No existe ninguna regla de decisión con ese código en tu equipo.',
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
