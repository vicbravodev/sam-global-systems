<?php

namespace App\Http\Requests\Incidents;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Http\Requests\Concerns\ResolvesCurrentTeam;
use App\Rules\TeamMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignIncidentRequest extends FormRequest
{
    use ResolvesCurrentTeam;

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
            'assigned_to_type' => ['required', 'string', Rule::in(array_column(AssigneeType::cases(), 'value'))],
            'assigned_to_id' => ['required', 'integer', ...$this->assigneeRules()],
            'role' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Los ids de usuario y de team son globales: el asignado debe ser del
     * tenant de la ruta (o un super-admin dando soporte).
     *
     * @return array<int, mixed>
     */
    private function assigneeRules(): array
    {
        $teamId = $this->currentTeamId();

        return match (AssigneeType::tryFrom((string) $this->input('assigned_to_type'))) {
            AssigneeType::User => [new TeamMember($teamId, allowSuperAdmins: true)],
            AssigneeType::Team, AssigneeType::Queue => [Rule::in([$teamId])],
            default => [],
        };
    }
}
