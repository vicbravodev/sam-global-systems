<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\TwilioErrorCatalog;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Aplica un estado reportado por Twilio a un recurso facturable y, si es de
 * una entrega de notificación, a la entrega y a su notificación. Lo usan el
 * status callback (`source = callback`) y el reconciliador (`source = poll`),
 * así que un callback perdido lo cubre el polling con la misma lógica.
 *
 * Transiciones monótonas: un evento que no hace avanzar la entrega (tardío,
 * fuera de orden o repetido) no la hace retroceder — `sent` llegando después
 * de `delivered` no cambia nada. Los terminales no se reabren; `read` sólo
 * añade `read_at` a una entrega ya entregada.
 *
 * Una entrega que pasa a `Failed` emite NotificationFailed: el listener de
 * reintento/fallback decide (permanente → fallback directo).
 */
class ApplyTwilioStatusUpdate
{
    /**
     * @var array<string, DeliveryStatus>
     */
    private const MESSAGE_STATUSES = [
        'accepted' => DeliveryStatus::Queued,
        'scheduled' => DeliveryStatus::Queued,
        'queued' => DeliveryStatus::Queued,
        'sending' => DeliveryStatus::Sending,
        'sent' => DeliveryStatus::Sent,
        'delivered' => DeliveryStatus::Delivered,
        'read' => DeliveryStatus::Delivered,
        'undelivered' => DeliveryStatus::Failed,
        'failed' => DeliveryStatus::Failed,
        'canceled' => DeliveryStatus::Failed,
    ];

    /**
     * Estados terminales de Twilio por tipo de recurso.
     *
     * @var array<string, list<string>>
     */
    public const TERMINAL = [
        'message' => ['delivered', 'read', 'undelivered', 'failed', 'canceled'],
        'call' => ['completed', 'busy', 'no-answer', 'failed', 'canceled'],
    ];

    public function __construct(
        private readonly RefreshNotificationStatus $refreshStatus,
    ) {}

    public static function isTerminal(MessagingResourceType $type, ?string $status): bool
    {
        return $status !== null && in_array($status, self::TERMINAL[$type->value], true);
    }

    /**
     * @param  string  $source  `callback` | `poll`
     */
    public function execute(
        MessagingCharge $charge,
        string $providerStatus,
        ?string $errorCode = null,
        ?int $durationSeconds = null,
        ?int $segments = null,
        string $source = 'callback',
        ?string $answeredBy = null,
    ): void {
        $providerStatus = strtolower(trim($providerStatus));
        $answeredBy = $answeredBy !== null && trim($answeredBy) !== '' ? strtolower(trim($answeredBy)) : null;
        $input = ['charge_id' => $charge->id, 'source' => $source, 'resource_type' => $charge->resource_type->value];

        if ($providerStatus === '') {
            SystemLog::skipped('notifications.provider_status.skipped', reason: 'empty_status', input: $input, debug: true);

            return;
        }

        $errorCode = $errorCode !== null && $errorCode !== '' && $errorCode !== '0' ? $errorCode : null;

        // Una llamada que contestó un buzón o un fax no es una persona: la
        // entrega falla (permanente para la voz, cae a otro canal) en vez de
        // contarse como contestada y bloquear el fallback.
        if ($charge->resource_type === MessagingResourceType::Call && self::isMachine($answeredBy) && in_array($providerStatus, ['in-progress', 'completed'], true)) {
            $errorCode = TwilioErrorCatalog::ANSWERED_BY_MACHINE;
        }

        TenantContext::for($charge->team_id, function () use ($charge, $providerStatus, $errorCode, $durationSeconds, $segments, $source, $input, $answeredBy) {
            $this->updateCharge($charge, $providerStatus, $errorCode, $durationSeconds, $segments, $source);

            if ($charge->source_type !== MessagingChargeSource::NotificationDelivery || $charge->source_id === null) {
                // Verificación de llamada, OTP…: sólo se actualiza el cargo.
                SystemLog::skipped('notifications.provider_status.skipped', reason: 'not_a_notification_delivery', input: $input, calc: [
                    'provider_status' => LoggableCode::guard($providerStatus),
                ], debug: true);

                return;
            }

            // Fila bloqueada: un callback y una consulta del reconciliador a la
            // vez no pueden leer el mismo estado y escribir uno que retroceda,
            // ni emitir dos NotificationFailed del mismo intento.
            $applied = DB::transaction(fn () => $this->updateDelivery($charge, $providerStatus, $errorCode, $durationSeconds, $segments));

            $input['delivery_id'] = $applied['delivery_id'];
            $calc = [
                'provider_status' => LoggableCode::guard($providerStatus),
                'provider_error_code' => LoggableCode::guard($errorCode),
                'duration_seconds' => $durationSeconds,
                'answered_by' => LoggableCode::guard($answeredBy),
            ];
            $result = ['from_status' => $applied['from'], 'to_status' => $applied['to']];

            match ($applied['outcome']) {
                // La entrega ya no existe: sólo se actualizó el cargo.
                'delivery_missing' => SystemLog::skipped('notifications.provider_status.skipped', reason: 'delivery_missing', input: $input, calc: $calc),
                // El SID ya no es el del intento actual (un reintento lo
                // reemplazó): sólo se actualizó su cargo.
                'superseded_attempt' => SystemLog::skipped('notifications.provider_status.skipped', reason: 'superseded_attempt', input: $input, calc: $calc),
                // Tardío, fuera de orden o repetido: la entrega no retrocede.
                'not_advancing' => SystemLog::skipped('notifications.provider_status.skipped', reason: 'not_advancing', input: $input, calc: $calc, result: $result, debug: true),
                'advanced' => SystemLog::ok('notifications.provider_status.applied', input: $input, calc: $calc, result: [
                    ...$result,
                    'permanent' => $applied['to'] === DeliveryStatus::Failed->value ? TwilioErrorCatalog::isPermanent($errorCode) : null,
                ]),
            };
        });
    }

    private function updateCharge(
        MessagingCharge $charge,
        string $status,
        ?string $errorCode,
        ?int $durationSeconds,
        ?int $segments,
        string $source,
    ): void {
        $events = $charge->events_json ?? [];
        $last = end($events);

        // Sin eventos end() devuelve false: no hay repetición.
        $isRepeat = is_array($last)
            && $last['status'] === $status
            && ($last['error_code'] ?? null) === $errorCode;

        if (! $isRepeat) {
            $events[] = [
                'status' => $status,
                'error_code' => $errorCode,
                'at' => now()->toIso8601String(),
                'source' => $source,
            ];
        }

        // A terminal status on the charge is not overwritten by a late
        // non-terminal one (callbacks are not ordered).
        $keepStatus = self::isTerminal($charge->resource_type, $charge->status)
            && ! self::isTerminal($charge->resource_type, $status);

        $charge->fill([
            'status' => $keepStatus ? $charge->status : $status,
            'error_code' => $errorCode ?? $charge->error_code,
            'duration_seconds' => $durationSeconds ?? $charge->duration_seconds,
            'segments' => $segments ?? $charge->segments,
            'events_json' => $events,
        ])->save();
    }

    /**
     * @return array{outcome: 'delivery_missing'|'superseded_attempt'|'not_advancing'|'advanced', from: ?string, to: ?string, delivery_id: ?int}
     */
    private function updateDelivery(
        MessagingCharge $charge,
        string $status,
        ?string $errorCode,
        ?int $durationSeconds,
        ?int $segments,
    ): array {
        $delivery = NotificationDelivery::query()->with(['notification', 'channel'])->lockForUpdate()->find($charge->source_id);

        // A retry replaces the delivery's SID: events for an older attempt
        // only update its charge, never the current attempt's state.
        if ($delivery === null) {
            return ['outcome' => 'delivery_missing', 'from' => null, 'to' => null, 'delivery_id' => null];
        }

        if ($delivery->provider_message_id !== $charge->provider_sid) {
            return ['outcome' => 'superseded_attempt', 'from' => $delivery->status->value, 'to' => null, 'delivery_id' => $delivery->id];
        }

        $now = now();
        $target = $charge->resource_type === MessagingResourceType::Call
            ? ($errorCode === TwilioErrorCatalog::ANSWERED_BY_MACHINE ? DeliveryStatus::Failed : $this->callTarget($delivery, $status, $durationSeconds))
            : (self::MESSAGE_STATUSES[$status] ?? null);

        $changes = [
            'provider_status' => $status,
            'last_provider_event_at' => $now,
        ];

        if ($errorCode !== null) {
            $changes['provider_error_code'] = $errorCode;
        }

        if ($segments !== null) {
            $changes['segments'] = $segments;
        }

        if ($durationSeconds !== null && $charge->resource_type === MessagingResourceType::Call) {
            $changes['call_duration_seconds'] = $durationSeconds;
        }

        if ($status === 'read' && $delivery->read_at === null) {
            $changes['read_at'] = $now;
        }

        if ($charge->resource_type === MessagingResourceType::Call && $status === 'in-progress' && $delivery->answered_at === null && $errorCode !== TwilioErrorCatalog::ANSWERED_BY_MACHINE) {
            $changes['answered_at'] = $now;
        }

        $current = $delivery->status;
        $advances = $target !== null
            && ! $current->isTerminal()
            && $target->progressRank() > $current->progressRank();

        if (! $advances) {
            // Late/duplicate event: keep the state. A non-terminal provider
            // status never overwrites the one of an already-final delivery
            // (but `completed` after `in-progress`, or `read`, still does).
            if ($current->isTerminal() && ! self::isTerminal($charge->resource_type, $status)) {
                unset($changes['provider_status']);
            }

            $delivery->fill($changes)->save();

            return ['outcome' => 'not_advancing', 'from' => $current->value, 'to' => $target?->value, 'delivery_id' => $delivery->id];
        }

        $changes['status'] = $target;

        if ($target === DeliveryStatus::Delivered) {
            $changes['delivered_at'] = $delivery->delivered_at ?? $now;
        }

        if ($target === DeliveryStatus::Failed) {
            $changes['failed_at'] = $now;
            $changes['permanent_failure'] = TwilioErrorCatalog::isPermanent($errorCode);
            $changes['error_message'] = $this->failureMessage($charge, $status, $errorCode);
        }

        $delivery->fill($changes)->save();

        if ($delivery->notification !== null) {
            $this->refreshStatus->execute($delivery->notification);
        }

        $channelType = $delivery->channel?->channel_type?->value ?? $charge->channel_type->value;

        if ($target === DeliveryStatus::Delivered) {
            NotificationDelivered::dispatch($delivery->team_id, $delivery->notification_id, $delivery->id, $channelType);
        }

        if ($target === DeliveryStatus::Failed) {
            NotificationFailed::dispatch(
                $delivery->team_id,
                $delivery->notification_id,
                $delivery->id,
                $channelType,
                $changes['error_message'],
            );
        }

        return ['outcome' => 'advanced', 'from' => $current->value, 'to' => $target->value, 'delivery_id' => $delivery->id];
    }

    /**
     * Voice notification calls count as delivered once answered: Twilio
     * reports `in-progress` on answer (statusCallbackEvent `answered`), and a
     * `completed` call with talk time was answered even if that event got
     * lost. `completed` without duration never connected.
     */
    private function callTarget(NotificationDelivery $delivery, string $status, ?int $durationSeconds): ?DeliveryStatus
    {
        return match ($status) {
            'queued' => DeliveryStatus::Queued,
            'initiated', 'ringing' => DeliveryStatus::Sending,
            'in-progress' => DeliveryStatus::Delivered,
            'completed' => ($durationSeconds ?? 0) > 0 || $delivery->answered_at !== null
                ? DeliveryStatus::Delivered
                : DeliveryStatus::Failed,
            'busy', 'no-answer', 'failed', 'canceled' => DeliveryStatus::Failed,
            default => null,
        };
    }

    private function failureMessage(MessagingCharge $charge, string $status, ?string $errorCode): string
    {
        $message = "twilio {$charge->resource_type->value} {$status}";

        return $errorCode !== null ? "{$message} (error {$errorCode})" : $message;
    }

    /**
     * `AnsweredBy` de la detección de contestadora (AMD): `machine_*` o `fax`.
     */
    public static function isMachine(?string $answeredBy): bool
    {
        return $answeredBy !== null && (str_starts_with($answeredBy, 'machine') || $answeredBy === 'fax');
    }
}
