<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Support\SystemLog;

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
 *   - el destinatario ya fue alcanzado por otro canal que interrumpe (SMS o
 *     WhatsApp aceptado, llamada contestada); la app y el correo no cuentan.
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
        return self::explain($delivery)['reason'];
    }

    /**
     * La misma cascada que {@see blockReason()}, con los términos de cada
     * paso para el log. `notification_age_seconds` (con signo, segundos desde
     * `created_at`; null sin `created_at`) se calcula en cuanto hay
     * notificación, aunque luego bloquee el tenant. El resto de pasos que la
     * cascada no llegó a evaluar van null.
     *
     * @return array{reason: ?string, calc: array{ttl_minutes: int, notification_age_seconds: ?int, incident_id: ?int, incident_handled_at_present: ?bool, reached_elsewhere: ?bool}}
     */
    public static function explain(NotificationDelivery $delivery): array
    {
        $calc = [
            'ttl_minutes' => self::TTL_MINUTES,
            'notification_age_seconds' => null,
            'incident_id' => null,
            'incident_handled_at_present' => null,
            'reached_elsewhere' => null,
        ];

        $notification = $delivery->notification;

        if ($notification === null) {
            return ['reason' => 'notification_missing', 'calc' => $calc];
        }

        if ($notification->created_at !== null) {
            $calc['notification_age_seconds'] = (int) $notification->created_at->diffInSeconds(now());
        }

        if (($blocked = TenantCanSend::blockedReason($delivery->team_id)) !== null) {
            return ['reason' => $blocked, 'calc' => $calc];
        }

        if ($notification->created_at !== null
            && $notification->created_at->lt(now()->subMinutes(self::TTL_MINUTES))) {
            return ['reason' => 'expired', 'calc' => $calc];
        }

        $handled = self::incidentHandledSince($notification->source_type, $notification->source_reference_id, $notification->created_at);

        // Sólo el id de un incidente del propio team de la entrega.
        if ($handled['incident'] !== null && $handled['incident']->team_id === $delivery->team_id) {
            $calc['incident_id'] = $handled['incident']->id;
            $calc['incident_handled_at_present'] = $handled['handled_at_present'];
        }

        if ($handled['handled']) {
            return ['reason' => 'incident_handled', 'calc' => $calc];
        }

        // Sólo cuenta un canal que interrumpe: el aviso en la app o el correo
        // se marcan entregados al instante y bloqueaban el reintento de un SMS
        // o una llamada críticos que fallaron. Una llamada cuenta sólo si se
        // contestó; un mensaje, en cuanto Twilio lo aceptó.
        $reachedElsewhere = NotificationDelivery::query()
            ->where('notification_id', $delivery->notification_id)
            ->where('recipient_id', $delivery->recipient_id)
            ->where('id', '!=', $delivery->id)
            ->where(function ($query): void {
                $query
                    ->where(fn ($messaging) => $messaging
                        ->whereHas('channel', fn ($channel) => $channel->whereIn('channel_type', [ChannelType::Sms->value, ChannelType::Whatsapp->value]))
                        ->whereIn('status', [DeliveryStatus::Queued, DeliveryStatus::Sent, DeliveryStatus::Delivered]))
                    ->orWhere(fn ($voice) => $voice
                        ->whereHas('channel', fn ($channel) => $channel->where('channel_type', ChannelType::Voice->value))
                        ->where('status', DeliveryStatus::Delivered));
            })
            ->exists();

        $calc['reached_elsewhere'] = $reachedElsewhere;

        return ['reason' => $reachedElsewhere ? 'recipient_reached' : null, 'calc' => $calc];
    }

    /**
     * Línea común de los tres sitios que consultan el guard.
     *
     * @param  array{reason: ?string, calc: array<string, mixed>}  $guard  resultado de {@see explain()} con `reason` no null
     * @param  string  $stage  `listener` | `retry_job` | `fallback_job`
     */
    public static function logBlocked(NotificationDelivery $delivery, array $guard, string $stage): void
    {
        SystemLog::skipped('notifications.escalation_guard.blocked',
            reason: self::logReason((string) $guard['reason']),
            input: ['delivery_id' => $delivery->id, 'notification_id' => $delivery->notification_id, 'stage' => $stage],
            calc: [...$guard['calc'], 'blocked_reason' => $guard['reason']],
        );
    }

    /**
     * El `reason` del log para un motivo de bloqueo: los de
     * {@see TenantCanSend} (`tenant_missing`, `subscription_*`) se agrupan en
     * `tenant_cannot_send`, como `notifications.notification.cancelled`; el
     * motivo exacto va en `calc.blocked_reason`.
     */
    public static function logReason(string $reason): string
    {
        if ($reason === TenantCanSend::REASON_TEAM_MISSING || str_starts_with($reason, 'subscription_')) {
            return 'tenant_cannot_send';
        }

        return $reason;
    }

    /**
     * @return array{handled: bool, incident: ?Incident, handled_at_present: ?bool}
     */
    private static function incidentHandledSince(
        ?NotificationSourceType $sourceType,
        ?string $sourceReferenceId,
        mixed $notifiedAt,
    ): array {
        if ($sourceType !== NotificationSourceType::Incident || ! is_numeric($sourceReferenceId)) {
            return ['handled' => false, 'incident' => null, 'handled_at_present' => null];
        }

        $incident = Incident::query()->find((int) $sourceReferenceId);

        if ($incident === null) {
            return ['handled' => false, 'incident' => null, 'handled_at_present' => null];
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
            return ['handled' => false, 'incident' => $incident, 'handled_at_present' => false];
        }

        return [
            'handled' => $notifiedAt === null || $handledAt->gte($notifiedAt),
            'incident' => $incident,
            'handled_at_present' => true,
        ];
    }
}
