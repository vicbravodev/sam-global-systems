<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => User::normalizeEmail($this->input('email'))]);
        }

        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => trim($this->input('phone'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);

        // El email es la identidad de login: cambiarlo exige la contraseña
        // actual (una sesión robada no basta para secuestrar la cuenta).
        if (User::normalizeEmail((string) $this->input('email')) !== User::normalizeEmail($this->user()->email)) {
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        return $rules;
    }
}
