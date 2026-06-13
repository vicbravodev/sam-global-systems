<?php

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use App\Rules\UniqueTeamInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // D-16: `email:rfc` por sí solo acepta direcciones sin TLD (p. ej.
            // "foo@bar"). Añadimos una regla regex que exige un dominio con TLD
            // de al menos 2 letras, sin depender de DNS (funciona offline/CI).
            'email' => [
                'required',
                'string',
                'email:rfc',
                'regex:/^[^@\s]+@[^@\s]+\.[a-zA-Z]{2,}$/',
                'max:255',
                new UniqueTeamInvitation($this->route('team')),
            ],
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.regex' => 'Ingresa un correo electrónico válido con dominio completo (por ejemplo, «nombre@empresa.com»).',
        ];
    }
}
