<?php

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use App\Support\TenantContext;

/**
 * Única puerta que decide si un tenant puede generar envíos con coste
 * (SMS, WhatsApp, llamadas, correo, OTP, acciones de automatización).
 *
 * Política (2026-09-27):
 *  - `trialing`, `active` y `past_due` SÍ envían: un pago atrasado no puede
 *    dejar a una flota sin alertas de pánico; si el admin quiere cortar,
 *    suspende la suscripción.
 *  - `suspended`, `canceled` y `expired` NO envían.
 *  - Team borrado (soft-delete) o inexistente NO envía.
 *  - Team sin fila de suscripción SÍ envía (mismo criterio que
 *    AuthorizeAction::checkSubscriptionAccess: la ausencia de suscripción no
 *    es un corte explícito).
 *
 * Los envíos bloqueados se registran como cancelados con el motivo que
 * devuelve {@see blockedReason()}; nunca se descartan en silencio.
 */
final class TenantCanSend
{
    public const string REASON_TEAM_MISSING = 'tenant_missing';

    public static function allows(?int $teamId): bool
    {
        return self::blockedReason($teamId) === null;
    }

    /**
     * Motivo legible por máquina cuando el tenant no puede enviar, o null
     * cuando puede.
     */
    public static function blockedReason(?int $teamId): ?string
    {
        if ($teamId === null || ! Team::query()->whereKey($teamId)->exists()) {
            return self::REASON_TEAM_MISSING;
        }

        $subscription = TenantContext::for($teamId, fn () => Subscription::query()
            ->where('team_id', $teamId)
            ->latest('starts_at')
            ->latest('id')
            ->first());

        if ($subscription === null) {
            return null;
        }

        /** @var SubscriptionStatus $status */
        $status = $subscription->status;

        if ($status->grantsOperationalAccess()) {
            return null;
        }

        return 'subscription_'.$status->value;
    }
}
