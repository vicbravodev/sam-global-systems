<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Tenancy\Support\TenantCanSend;

/**
 * ¿Sigue teniendo sentido reintentar o caer a otro canal para una entrega
 * fallida? Cada reintento/fallback es un envío cobrable; estos son los casos
 * en que ya no aporta nada:
 *
 *   - el tenant ya no puede enviar ({@see TenantCanSend}: suscripción
 *     suspendida/cancelada/expirada o team borrado);
 *   - la notificación es vieja (TTL): un aviso de emergencia de hace media
 *     hora ya no es accionable por SMS;
 *   - el incidente origen ya lo atendió alguien (reconocido, tomado,
 *     resuelto o cerrado) DESPUÉS de crearse la notificación — los avisos
 *     posteriores (p.ej. "incidente cerrado") sí se siguen reintentando;
 *   - el destinatario ya fue alcanzado por otra entrega (otro canal llegó,
 *     aunque sea tarde).
 *
 * Lo consultan el listener que programa el reintento/fallback y los propios
 * jobs al ejecutarse (van con retardo y el mundo pudo cambiar entretanto).
 * Debe llamarse dentro del TenantContext de la entrega.
 */
final class DeliveryEscalationGuard
{
    public const TTL_MINUTES = 30;

    /**
     * Motivo para NO escalar, o null si se puede reintentar/hacer fallback.
     */
    public static function blockReason(NotificationDelivery $delivery): ?string
    {
        $notification = $delivery->notification;

        if ($notification === null) {
            return 'notification_missing';
        }

        if (($blocked = TenantCanSend::blockedReason((int) $delivery->team_id)) !== null) {
            return $blocked;
        }

        if ($notification->created_at !== null
            && $notification->created_at->lt(now()->subMinutes(self::TTL_MINUTES))) {
            return 'expired';
        }

        if (self::incidentHandledSince($notification->source_type, $notification->source_reference_id, $notification->created_at)) {
            return 'incident_handled';
        }

        $reachedElsewhere = NotificationDelivery::query()
            ->where('notification_id', $delivery->notification_id)
            ->where('recipient_id', $delivery->recipient_id)
            ->where('id', '!=', $delivery->id)
            ->whereIn('status', [DeliveryStatus::Queued, DeliveryStatus::Sent, DeliveryStatus::Delivered])
            ->exists();

        return $reachedElsewhere ? 'recipient_reached' : null;
    }

    private static function incidentHandledSince(
        ?NotificationSourceType $sourceType,
        ?string $sourceReferenceId,
        mixed $notifiedAt,
    ): bool {
        if ($sourceType !== NotificationSourceType::Incident || ! is_numeric($sourceReferenceId)) {
            return false;
        }

        $incident = Incident::query()->find((int) $sourceReferenceId);

        if ($incident === null) {
            return false;
        }

        $handledAt = collect([
            $incident->acknowledged_at,
            $incident->claimed_at,
            $incident->resolved_at,
            $incident->closed_at,
        ])->filter()->max();

        if ($handledAt === null) {
            // Tomado sin marca de tiempo, o terminal por estado: igualmente
            // atendido, pero sin saber cuándo — no bloquear avisos que
            // pudieron generarse por ese mismo cambio.
            return false;
        }

        return $notifiedAt === null || $handledAt->gte($notifiedAt);
    }
}
