<?php

namespace App\Http\Requests\Access;

use App\Domains\Access\Enums\RoleScope;
use App\Http\Requests\Concerns\ResolvesCurrentTeam;
use App\Models\Membership;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    use ResolvesCurrentTeam;

    /**
     * La pertenencia al tenant se comprueba ANTES de validar: una membresía de
     * otro team responde 404 igual que una inexistente (con payload válido o
     * vacío), así no hay oráculo de existencia cross-tenant vía 422.
     */
    public function authorize(): bool
    {
        $membership = $this->route('membership');

        abort_unless(
            $membership instanceof Membership && $membership->team_id === $this->currentTeamId(),
            404,
        );

        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            // Sólo roles de sistema de alcance tenant o roles propios de este
            // tenant: nunca un rol personalizado de otro tenant.
            'role_code' => [
                'required',
                'string',
                Rule::exists('roles', 'code')->where(function ($query) {
                    $team = $this->route('current_team');
                    $teamId = $team instanceof Team ? $team->id : 0;

                    $query->where('scope', RoleScope::Tenant->value)
                        ->where(fn ($q) => $q
                            ->where(fn ($q) => $q->whereNull('team_id')->where('is_system', true))
                            ->orWhere('team_id', $teamId));
                }),
            ],
        ];
    }
}
