<?php

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Http\Middleware\EnsureTenantNotSuspended;
use App\Support\TenantContext;

/**
 * Suscripción vigente de un team: la de `starts_at` más reciente y, a igual
 * fecha, la de id mayor. Es el criterio de {@see TenantCanSend} (puerta de
 * envíos) y del acceso web de los miembros
 * ({@see EnsureTenantNotSuspended}).
 *
 * Un team sin fila de suscripción devuelve null: la ausencia de suscripción
 * no es un corte explícito.
 */
final class CurrentSubscription
{
    public static function for(int $teamId): ?Subscription
    {
        return TenantContext::for($teamId, fn () => Subscription::query()
            ->where('team_id', $teamId)
            ->latest('starts_at')
            ->latest('id')
            ->first());
    }

    public static function status(int $teamId): ?SubscriptionStatus
    {
        /** @var SubscriptionStatus|null $status */
        $status = self::for($teamId)?->status;

        return $status;
    }

    public static function isSuspended(int $teamId): bool
    {
        return self::status($teamId) === SubscriptionStatus::Suspended;
    }
}
