<?php

namespace App\Domains\AI\Support;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Condiciones globales para correr la medición en sombra con Clef. La
 * ventana (`ai.clef.shadow_until`, inclusiva hasta el fin de ese día) hace
 * que la sombra se apague sola: vacía o vencida = cerrada.
 */
class ClefShadowGate
{
    /**
     * @param  bool  $requireWindow  false sólo revisa credenciales (backfill manual)
     * @return string|null null si está abierto; si no, `disabled`, `shadow_expired` o `missing_credentials`
     */
    public function closedReason(bool $requireWindow = true): ?string
    {
        if ($requireWindow && ! (bool) config('ai.clef.enabled', false)) {
            return 'disabled';
        }

        if ($requireWindow && ! $this->windowOpen()) {
            return 'shadow_expired';
        }

        if (blank(config('services.cloudflare.account_id')) || blank(config('services.cloudflare.auth_token'))) {
            return 'missing_credentials';
        }

        return null;
    }

    private function windowOpen(): bool
    {
        $until = config('ai.clef.shadow_until');

        if (! is_string($until) || trim($until) === '') {
            return false;
        }

        try {
            return now()->lessThanOrEqualTo(Carbon::parse($until)->endOfDay());
        } catch (Throwable) {
            return false;
        }
    }
}
