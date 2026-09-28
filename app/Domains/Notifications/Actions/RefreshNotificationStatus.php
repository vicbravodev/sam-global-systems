<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Support\TenantContext;

/**
 * Recalcula el estado agregado de una notificación a partir de sus entregas.
 * Se llama tras el fan-out y cada vez que una entrega cambia (reintento,
 * fallback, status callback, reconciliador).
 *
 * Regla, por DESTINATARIO (así un fallback que llega compensa al canal
 * primario que falló):
 *
 *   - alcanzado: alguna entrega suya está `Queued` / `Sent` / `Delivered`;
 *   - fallido:   todas sus entregas intentadas acabaron `Failed`/`Bounced`;
 *   - pendiente: el resto (entrega aún `Pending`/`Sending`/`Retrying`).
 *
 * Las entregas `Skipped`/`Cancelled` no cuentan como intento. Entonces:
 *
 *   - sin entregas intentadas            → `Cancelled`
 *   - alcanzados y ninguno fallido       → `Sent`
 *   - alcanzados y algún fallido         → `PartiallySent`
 *   - ninguno alcanzado, alguno pendiente → `Queued`
 *   - ninguno alcanzado ni pendiente     → `Failed`
 *
 * `sent_at` se fija la primera vez que la notificación alcanza a alguien.
 */
class RefreshNotificationStatus
{
    public function execute(Notification $notification): Notification
    {
        return TenantContext::for($notification->team_id, function () use ($notification) {
            $deliveries = NotificationDelivery::query()
                ->where('notification_id', $notification->id)
                ->whereNotIn('status', [DeliveryStatus::Skipped, DeliveryStatus::Cancelled])
                ->get(['recipient_id', 'status']);

            if ($deliveries->isEmpty()) {
                $notification->update(['status' => NotificationStatus::Cancelled]);

                return $notification;
            }

            $reached = 0;
            $failed = 0;
            $pending = 0;

            foreach ($deliveries->groupBy('recipient_id') as $recipientDeliveries) {
                $statuses = $recipientDeliveries->pluck('status');

                if ($statuses->contains(fn (DeliveryStatus $status) => $status->isReached())) {
                    $reached++;
                } elseif ($statuses->every(fn (DeliveryStatus $status) => in_array($status, [DeliveryStatus::Failed, DeliveryStatus::Bounced], true))) {
                    $failed++;
                } else {
                    $pending++;
                }
            }

            $status = match (true) {
                $reached > 0 && $failed === 0 => NotificationStatus::Sent,
                $reached > 0 => NotificationStatus::PartiallySent,
                $pending > 0 => NotificationStatus::Queued,
                default => NotificationStatus::Failed,
            };

            $notification->update([
                'status' => $status,
                'sent_at' => $reached > 0 ? ($notification->sent_at ?? now()) : $notification->sent_at,
            ]);

            return $notification;
        });
    }
}
