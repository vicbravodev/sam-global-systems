<?php

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamMemberRequest extends FormRequest
{
    /**
     * El usuario de la ruta debe ser miembro del team ANTES de validar: uno
     * ajeno responde 404 igual que un id inexistente, sin oráculo vía 422.
     */
    public function authorize(): bool
    {
        $team = $this->route('team');
        $user = $this->route('user');

        abort_unless(
            $team instanceof Team
                && $user instanceof User
                && $team->memberships()->where('user_id', $user->id)->exists(),
            404,
        );

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(array_column(TeamRole::assignable(), 'value'))],
        ];
    }
}
