<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Support\TenantContext;

/**
 * Escala una entrega fallida: reintenta con el backoff por canal que declara
 * RetryNotificationDeliveryJob y, agotados los intentos, cae al canal de
 * fallback de la TenantNotificationPolicy vía FallbackNotificationChannelJob.
 *
 * Cada reintento fallido vuelve a emitir NotificationFailed, así que este
 * listener también avanza el bucle hasta agotarlo. El ping-pong entre canales
 * termina porque FallbackNotificationChannelJob deduplica por
 * (notification, recipient, channel).
 */
class RetryOrFallbackOnNotificationFailed
{
    public function handle(NotificationFailed $event): void
    {
        $delivery = NotificationDelivery::query()->find($event->deliveryId);

        if ($delivery === null || $delivery->status !== DeliveryStatus::Failed) {
            return;
        }

        // Trabaja dentro del tenant de la entrega. Ver §2.1.
        TenantContext::set($delivery->team_id);

        $retry = new RetryNotificationDeliveryJob($delivery->id);

        if ($delivery->attempt_number >= $retry->tries()) {
            FallbackNotificationChannelJob::dispatch($delivery->id);

            return;
        }

        $backoff = $retry->backoff();
        $step = max(0, min($delivery->attempt_number, count($backoff)) - 1);

        dispatch($retry)->delay($backoff[$step]);
    }
}
