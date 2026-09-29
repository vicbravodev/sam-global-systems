<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Support\SystemLog;

/**
 * Corta una notificación de un tenant que no puede enviar (suscripción
 * suspendida/cancelada/expirada o team borrado): queda `cancelled` con el
 * motivo en `payload_json.cancelled_reason`, visible en el centro de
 * notificaciones, en vez de salir por Twilio y facturarse.
 */
final class CancelBlockedNotification
{
    /**
     * @return bool true cuando la notificación quedó cancelada y no debe enviarse.
     */
    public static function apply(Notification $notification): bool
    {
        $reason = TenantCanSend::blockedReason($notification->team_id);

        if ($reason === null) {
            return false;
        }

        $payload = $notification->payload_json ?? [];
        $payload['cancelled_reason'] = $reason;

        $notification->update([
            'status' => NotificationStatus::Cancelled,
            'payload_json' => $payload,
        ]);

        SystemLog::skipped('notifications.notification.cancelled', reason: 'tenant_cannot_send', input: ['notification_id' => $notification->id, 'team_id' => $notification->team_id, 'blocked_reason' => $reason]);

        return true;
    }
}
