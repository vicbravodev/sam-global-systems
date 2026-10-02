<?php

namespace App\Rules;

use App\Domains\Incidents\Actions\ResolveEscalationAudience;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Cada paso de la escalera puede fijar a quién avisa (`audience`): sólo los
 * escalones que conoce ResolveEscalationAudience. Regla sobre `steps` entero
 * (no `steps.*.audience`) para no recortar el resto de cada paso del
 * payload validado.
 */
class ValidEscalationAudiences implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $step) {
            $audience = is_array($step) ? ($step['audience'] ?? null) : null;

            if ($audience !== null && ! in_array($audience, ResolveEscalationAudience::AUDIENCES, true)) {
                $fail('Cada paso sólo puede avisar a: '.implode(', ', ResolveEscalationAudience::AUDIENCES).'.');

                return;
            }
        }
    }
}
