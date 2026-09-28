<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Jobs\FallbackNotificationChannelJob;
use App\Domains\Notifications\Jobs\RetryNotificationDeliveryJob;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Support\TenantContext;

/**
 * Escala una entrega fallida: reintenta con el retardo por canal que declara
 * RetryNotificationDeliveryJob y, agotados los intentos, cae al canal de
 * fallback de la TenantNotificationPolicy vía FallbackNotificationChannelJob.
 *
 * - Fallo permanente (número inválido, opt-out, WhatsApp fuera de ventana,
 *   canal sin configurar): reintentar por el mismo canal es tirar dinero,
 *   así que salta directo al fallback.
 * - {@see DeliveryEscalationGuard}: ni reintento ni fallback si la
 *   notificación caducó, el incidente ya lo atendió alguien o el
 *   destinatario ya fue alcanzado por otro canal.
 *
 * Cada reintento fallido vuelve a emitir NotificationFailed, así que este
 * listener también avanza el bucle hasta agotarlo. El ping-pong entre canales
 * termina porque FallbackNotificationChannelJob deduplica por tipo de canal
 * y destinatario.
 */
class RetryOrFallbackOnNotificationFailed
{
    public function handle(NotificationFailed $event): void
    {
        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->where('team_id', $event->teamId)
            ->find($event->deliveryId);

        if ($delivery === null || $delivery->status !== DeliveryStatus::Failed) {
            return;
        }

        // Trabaja dentro del tenant de la entrega. Ver §2.1.
        TenantContext::set($delivery->team_id);

        if (DeliveryEscalationGuard::blockReason($delivery) !== null) {
            return;
        }

        $retry = new RetryNotificationDeliveryJob($delivery->id);

        if ($delivery->permanent_failure || $delivery->attempt_number >= $retry->maxAttempts()) {
            FallbackNotificationChannelJob::dispatch($delivery->id);

            return;
        }

        $delays = $retry->retryDelays();
        $step = max(0, min($delivery->attempt_number, count($delays)) - 1);

        dispatch($retry)->delay($delays[$step]);
    }
}
