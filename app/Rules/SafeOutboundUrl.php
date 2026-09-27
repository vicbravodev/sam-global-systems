<?php

namespace App\Rules;

use App\Support\Http\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL a la que SAM hará peticiones salientes en nombre de un tenant: debe
 * pasar OutboundUrlGuard (https, sin red interna). Se valida al guardar para
 * avisar pronto; ExecuteAction lo vuelve a comprobar al ejecutar.
 */
class SafeOutboundUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(OutboundUrlGuard::class)->isSafe($value)) {
            $fail('La URL debe ser https y apuntar a un servidor público.');
        }
    }
}
