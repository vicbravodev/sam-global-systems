<?php

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\UniqueTeamInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamInvitationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }
    }

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
                new UniqueTeamInvitation($this->team()),
            ],
            // Nunca `owner`: la propiedad sólo la reasigna el super-admin. Y
            // nadie concede un rol por encima del suyo.
            'role' => [
                'required',
                'string',
                Rule::in(array_column(TeamRole::assignable(), 'value')),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $requested = TeamRole::tryFrom((string) $value);
                    $own = $this->user()?->teamRole($this->team());

                    if ($requested !== null && ($own === null || ! $own->isAtLeast($requested))) {
                        $fail('No puedes invitar con un rol superior al tuyo.');
                    }
                },
            ],
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

    /**
     * El equipo del binding implícito `{team}` (ya resuelto al validar).
     */
    private function team(): Team
    {
        $team = $this->route('team');

        abort_unless($team instanceof Team, 404);

        return $team;
    }
}
