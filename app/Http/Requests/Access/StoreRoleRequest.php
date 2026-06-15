<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
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
                'unique:roles,code',
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
