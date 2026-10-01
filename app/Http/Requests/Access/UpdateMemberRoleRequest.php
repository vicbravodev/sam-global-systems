<?php

namespace App\Http\Requests\Access;

use App\Domains\Access\Enums\RoleScope;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
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
