<?php

namespace App\Http\Requests\Access;

use App\Domains\Access\Models\Role;
use App\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // D-21: el código de rol se restringe a slug (minúsculas, dígitos y
            // separadores `-`/`_`), evitando espacios, mayúsculas o símbolos.
            'code' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/',
                // Único por tenant: el código real se guarda namespaceado
                // (Role::customCodeFor) para no chocar con otros tenants.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $team = $this->route('current_team');

                    if ($team instanceof Team && is_string($value)
                        && Role::query()->where('code', Role::customCodeFor((int) $team->id, $value))->exists()) {
                        $fail('Ya existe un rol con este código en tu empresa.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', 'exists:permissions,code'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'El código solo puede contener minúsculas, números y los separadores «-» o «_» (por ejemplo, «turno-noche»).',
        ];
    }
}
