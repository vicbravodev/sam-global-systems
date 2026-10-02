<?php

namespace App\Http\Requests\Access;

use App\Domains\Access\Models\Role;
use App\Http\Requests\Concerns\ResolvesCurrentTeam;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    use ResolvesCurrentTeam;

    /**
     * Un rol de otro tenant no existe para este: 404 ANTES de validar, igual
     * que un id inexistente (sin oráculo de existencia vía 422).
     */
    public function authorize(): bool
    {
        $role = $this->route('role');
        $teamId = $this->currentTeamId();

        abort_unless($role instanceof Role && $teamId !== null && $role->isVisibleToTeam($teamId), 404);

        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', 'exists:permissions,code'],
        ];
    }
}
