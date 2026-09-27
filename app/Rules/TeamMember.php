<?php

namespace App\Rules;

use App\Support\TeamMembers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El valor es el id de un usuario miembro del team dado. Los ids de usuario
 * son globales: sin esta regla un tenant puede apuntar a usuarios de otro.
 */
class TeamMember implements ValidationRule
{
    public function __construct(
        private readonly ?int $teamId,
        private readonly bool $allowSuperAdmins = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $userId = filter_var($value, FILTER_VALIDATE_INT);

        $valid = $this->teamId !== null
            && $userId !== false
            && ($this->allowSuperAdmins
                ? TeamMembers::isAssignable($this->teamId, $userId)
                : TeamMembers::isMember($this->teamId, $userId));

        if (! $valid) {
            $fail('El usuario seleccionado no pertenece a este equipo.');
        }
    }
}
