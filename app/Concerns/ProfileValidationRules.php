<?php

namespace App\Concerns;

use App\Models\User;
use App\Rules\ValidE164Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Stringable;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Stringable|Closure|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'phone' => $this->phoneRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|Stringable|Closure|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate a user's phone (E.164).
     *
     * @return array<int, ValidationRule|Stringable|Closure|array<mixed>|string>
     */
    protected function phoneRules(): array
    {
        return ['nullable', 'string', new ValidE164Phone];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|Stringable|Closure|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
            // `unique` compara tal cual; en Postgres eso distingue mayúsculas.
            // Este chequeo cubre filas históricas con mayúsculas.
            function (string $attribute, mixed $value, Closure $fail) use ($userId): void {
                $existing = is_string($value) ? User::findByEmail($value) : null;

                if ($existing !== null && $existing->id !== $userId) {
                    $fail(__('validation.unique', ['attribute' => $attribute]));
                }
            },
        ];
    }
}
