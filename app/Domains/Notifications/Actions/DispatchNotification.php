<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Data\RecipientDescriptor;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Events\NotificationCreated;
use App\Domains\Notifications\Events\NotificationPushedBroadcast;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\CancelBlockedNotification;
use App\Domains\Notifications\Support\ChannelAddress;
use App\Domains\Notifications\Support\MessagingSuppressions;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

class DispatchNotification
{
    public function __construct(
        private readonly ResolveRecipients $resolveRecipients,
        private readonly SelectNotificationChannels $selectChannels,
        private readonly RenderNotificationContent $render,
        private readonly AppendReplyInstructions $appendReplyInstructions,
        private readonly AttemptDelivery $attemptDelivery,
        private readonly RefreshNotificationStatus $refreshStatus,
    ) {}

    public function execute(Notification $notification): Notification
    {
        if (CancelBlockedNotification::apply($notification)) {
            return $notification;
        }

        // Corre en SendNotificationJob, sin transacción: las líneas van directas.
        $input = ['notification_id' => $notification->id];

        // Un aviso de escalación que esperó en cola mientras alguien del equipo
        // atendía el incidente ya no tiene a quién despertar: no sale (y no se
        // paga). El watchdog lo comprobó al crearlo; esto cubre el hueco entre
        // ese momento y el envío.
        $handled = self::escalationNoticeForHandledIncident($notification);

        if ($handled !== null) {
            $notification->update(['status' => NotificationStatus::Cancelled]);

            SystemLog::skipped('notifications.escalation_notice.cancelled', reason: 'incident_handled', input: $input, calc: $handled);

            return $notification;
        }

        $explain = $this->resolveRecipients->explain($notification);
        $descriptors = $explain['descriptors'];
        $recipientCalc = [
            'source' => $explain['source'],
            'candidates_count' => $explain['candidates_count'],
            'dropped_count_by_reason' => self::countKeys($explain['dropped_count_by_reason']),
        ];

        if ($descriptors === []) {
            $notification->update(['status' => NotificationStatus::Cancelled]);

            SystemLog::skipped('notifications.recipients.resolved', reason: 'no_recipients', input: $input, calc: $recipientCalc);

            return $notification;
        }

        $recipientsExisted = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->exists();

        $recipients = [];

        foreach ($descriptors as $descriptor) {
            $recipients[] = $this->firstOrCreateRecipient($notification, $descriptor);
        }

        SystemLog::ok('notifications.recipients.resolved', input: $input, calc: $recipientCalc, result: [
            'recipient_ids' => array_map(fn (NotificationRecipient $recipient) => $recipient->id, $recipients),
            'recipients_count' => count($recipients),
            'recipients_reused' => $recipientsExisted,
        ]);

        // Only the genuine first fan-out announces the notification; a retried
        // SendNotificationJob ($tries = 3) re-enters here with recipients already
        // persisted and must not re-emit NotificationCreated.
        if (! $recipientsExisted) {
            NotificationCreated::dispatch(
                $notification->team_id,
                $notification->id,
                $notification->notification_type,
                count($recipients),
            );
        }

        $attempted = 0;
        $sent = 0;
        $failed = 0;
        /** @var array<string, int> $skippedByReason */
        $skippedByReason = [];

        foreach ($recipients as $recipient) {
            $selection = $this->selectChannels->explain($notification, $recipient);
            $channels = $selection['channels'];

            $recipientInput = $input + [
                'recipient_id' => $recipient->id,
                'recipient_type' => $recipient->recipient_type->value,
            ];
            $selectionCalc = [...$selection['calc'], 'branch' => $selection['branch']];

            if ($channels === []) {
                // Antes una selección vacía no dejaba rastro ni en la DB.
                $reason = match ($selection['branch']) {
                    'muted' => 'muted',
                    'no_usable_channels' => 'no_usable_channels',
                    default => 'no_channel_after_filters',
                };

                SystemLog::skipped('notifications.channels.selected', reason: $reason, input: $recipientInput, calc: $selectionCalc);
            } else {
                SystemLog::ok('notifications.channels.selected', input: $recipientInput, calc: $selectionCalc, debug: true);
            }

            foreach ($channels as $channel) {
                $channelInput = $input + [
                    'recipient_id' => $recipient->id,
                    'channel_id' => $channel->id,
                    'channel_type' => $channel->channel_type->value,
                ];

                $targetAddress = $recipient->addressForChannel($channel->channel_type);

                if ($targetAddress === null || $targetAddress === '') {
                    $this->recordSkippedDelivery(
                        $notification,
                        $recipient,
                        $channel,
                        "no {$channel->channel_type->value} address (missing phone/email) for recipient",
                    );

                    SystemLog::skipped('notifications.delivery.skipped', reason: 'no_address', input: $channelInput);
                    $skippedByReason['no_address'] = ($skippedByReason['no_address'] ?? 0) + 1;

                    continue;
                }

                // A phone channel fed an email (or vice versa) is a billable
                // send doomed to fail at the provider: skip it with a reason.
                if (($invalid = ChannelAddress::invalidReason($channel->channel_type, $targetAddress)) !== null) {
                    $this->recordSkippedDelivery($notification, $recipient, $channel, $invalid);

                    // El texto de invalidReason no se registra.
                    SystemLog::skipped('notifications.delivery.skipped', reason: 'invalid_address', input: $channelInput);
                    $skippedByReason['invalid_address'] = ($skippedByReason['invalid_address'] ?? 0) + 1;

                    continue;
                }

                // Dirección dada de baja para este canal (STOP, fijo, sin
                // WhatsApp): no se intenta; los demás canales siguen.
                $suppressed = MessagingSuppressions::reasonFor($channel->channel_type, $targetAddress);

                if ($suppressed !== null) {
                    $this->recordSkippedDelivery($notification, $recipient, $channel, "address unavailable for {$channel->channel_type->value}");

                    SystemLog::skipped('notifications.delivery.skipped', reason: 'suppressed', input: $channelInput, calc: ['suppression_reason' => $suppressed]);
                    $skippedByReason['suppressed'] = ($skippedByReason['suppressed'] ?? 0) + 1;

                    continue;
                }

                // Anti-ruido: la misma persona ya recibió hace segundos un SMS,
                // WhatsApp o llamada de ESTE incidente por otro aviso (creado +
                // emergencia confirmada, verificación + SLA...). No se repite
                // por canal pagado; la app, el correo y el push siguen.
                $cooldown = $this->recentPaidContact($notification, $channel, $recipient, $targetAddress);

                if ($cooldown !== null) {
                    $this->recordSkippedDelivery($notification, $recipient, $channel, "paid cooldown: this incident already reached this address {$cooldown['seconds_ago']}s ago");

                    SystemLog::skipped('notifications.delivery.skipped', reason: 'paid_cooldown', input: $channelInput, calc: $cooldown);
                    $skippedByReason['paid_cooldown'] = ($skippedByReason['paid_cooldown'] ?? 0) + 1;

                    continue;
                }

                $delivery = $this->createDeliveryOrSkip($notification, $recipient, $channel);

                if (is_string($delivery)) {
                    $skippedByReason[$delivery] = ($skippedByReason[$delivery] ?? 0) + 1;

                    continue;
                }

                $rendered = $this->render->execute($notification, $recipient, $channel->channel_type, null, $targetAddress);
                $rendered = $this->appendReplyInstructions->execute($notification, $recipient, $rendered);

                $attempted++;

                $result = $this->attemptDelivery->execute(
                    $delivery,
                    $channel,
                    $rendered,
                    usageEventKey: "notif_delivery_{$delivery->id}",
                    refreshNotificationStatus: false,
                );

                if ($result->success) {
                    $sent++;
                    $this->broadcastWebDelivery($notification, $recipient, $channel->channel_type);
                } else {
                    $failed++;
                }
            }
        }

        $this->refreshStatus->execute($notification);
        $notification->refresh();

        SystemLog::ok('notifications.dispatch.completed', input: $input, calc: [
            'recipients_count' => count($recipients),
            'deliveries_attempted_count' => $attempted,
            'deliveries_skipped_count_by_reason' => self::countKeys($skippedByReason),
            'sent_count' => $sent,
            'failed_count' => $failed,
        ], result: ['notification_status' => $notification->status->value]);

        return $notification;
    }

    /**
     * Claves `{reason}_count` en el log: `no_address`/`no_email` como clave
     * las enmascararía el redactor (segmento sensible sin sufijo técnico).
     *
     * @param  array<string, int>  $byReason
     * @return array<string, int>
     */
    private static function countKeys(array $byReason): array
    {
        $out = [];

        foreach ($byReason as $reason => $count) {
            $out["{$reason}_count"] = $count;
        }

        return $out;
    }

    private function recordSkippedDelivery(
        Notification $notification,
        NotificationRecipient $recipient,
        NotificationChannel $channel,
        string $reason,
    ): void {
        try {
            DB::transaction(function () use ($notification, $recipient, $channel, $reason) {
                $existing = NotificationDelivery::query()
                    ->where('notification_id', $notification->id)
                    ->where('recipient_id', $recipient->id)
                    ->where('channel_id', $channel->id)
                    ->first();

                if ($existing !== null) {
                    return;
                }

                NotificationDelivery::query()->create([
                    'notification_id' => $notification->id,
                    'recipient_id' => $recipient->id,
                    'channel_id' => $channel->id,
                    'team_id' => $notification->team_id,
                    'status' => DeliveryStatus::Skipped,
                    'attempt_number' => 0,
                    'error_message' => $reason,
                ]);
            });
        } catch (\Throwable $e) {
            // best-effort audit row; never break the dispatch loop, but log so
            // a systemic failure (e.g. DB constraint) stays debuggable.
            SystemLog::degraded('notifications.delivery.skip_record_failed', reason: 'record_failed', input: ['notification_id' => $notification->id, 'recipient_id' => $recipient->id, 'channel_id' => $channel->id, 'skip_reason' => $reason], error: $e);
        }
    }

    /**
     * Recipients are created idempotently keyed on a stable per-notification
     * identity (type + reference + address). A retried SendNotificationJob
     * ($tries = 3) therefore reuses the same NotificationRecipient rows, which
     * is what lets the delivery dedup ({@see createDeliveryOrSkip}, unique on
     * notification_id + recipient_id + channel_id) hold across retries and
     * stops duplicate real sends of emergency alerts.
     *
     * `email` / `phone` are per-channel destinations resolved alongside the
     * recipient; they are written on creation but stay out of the identity key,
     * which stays anchored on the descriptor's stable `address`.
     */
    private function firstOrCreateRecipient(
        Notification $notification,
        RecipientDescriptor $descriptor,
    ): NotificationRecipient {
        $existing = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->where('recipient_type', $descriptor->recipientType->value)
            ->where('address', $descriptor->address)
            ->when(
                $descriptor->referenceId === null,
                fn ($query) => $query->whereNull('recipient_reference_id'),
                fn ($query) => $query->where('recipient_reference_id', $descriptor->referenceId),
            )
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return NotificationRecipient::query()->create([
            'notification_id' => $notification->id,
            'team_id' => $notification->team_id,
            'recipient_type' => $descriptor->recipientType,
            'recipient_reference_id' => $descriptor->referenceId,
            'name' => $descriptor->name,
            'address' => $descriptor->address,
            'email' => $descriptor->email,
            'phone' => $descriptor->phone,
            'channel_preference' => $descriptor->channelPreference,
            'role' => $descriptor->role,
            'metadata_json' => $descriptor->metadata,
        ]);
    }

    /**
     * La entrega creada, o el motivo por el que se omitió.
     *
     * @return NotificationDelivery|'delivery_exists'|'record_failed'
     */
    private function createDeliveryOrSkip(
        Notification $notification,
        NotificationRecipient $recipient,
        NotificationChannel $channel,
    ): NotificationDelivery|string {
        $input = ['notification_id' => $notification->id, 'recipient_id' => $recipient->id, 'channel_id' => $channel->id];

        try {
            $delivery = DB::transaction(function () use ($notification, $recipient, $channel) {
                $existing = NotificationDelivery::query()
                    ->where('notification_id', $notification->id)
                    ->where('recipient_id', $recipient->id)
                    ->where('channel_id', $channel->id)
                    ->first();

                if ($existing !== null) {
                    return null;
                }

                return NotificationDelivery::query()->create([
                    'notification_id' => $notification->id,
                    'recipient_id' => $recipient->id,
                    'channel_id' => $channel->id,
                    'team_id' => $notification->team_id,
                    'status' => DeliveryStatus::Pending,
                    'attempt_number' => 1,
                ]);
            });
        } catch (\Throwable $e) {
            // Error de DB, sin texto de terceros: antes se tragaba sin rastro.
            SystemLog::degraded('notifications.delivery.create_failed', reason: 'record_failed', input: $input, error: $e);

            return 'record_failed';
        }

        if ($delivery === null) {
            SystemLog::skipped('notifications.dedup.skipped', reason: 'delivery_exists', input: $input, debug: true);

            return 'delivery_exists';
        }

        return $delivery;
    }

    private function broadcastWebDelivery(
        Notification $notification,
        NotificationRecipient $recipient,
        ChannelType $channelType,
    ): void {
        if ($channelType !== ChannelType::Web) {
            return;
        }

        $userId = $recipient->recipient_reference_id !== null && is_numeric($recipient->recipient_reference_id)
            ? (int) $recipient->recipient_reference_id
            : null;

        if ($userId === null) {
            return;
        }

        broadcast(new NotificationPushedBroadcast(
            userId: $userId,
            notificationId: $notification->id,
            notificationType: $notification->notification_type,
            priority: $notification->priority->value,
            subject: $notification->subject,
            bodyPreview: $notification->body_preview,
            teamId: $notification->team_id,
        ));
    }

    /**
     * Términos de por qué el incidente ya está atendido, o null si el aviso no
     * es de escalación o el incidente sigue sin atender.
     *
     * @return array{incident_id: int, acknowledged: bool, claimed: bool, terminal: bool}|null
     */
    private static function escalationNoticeForHandledIncident(Notification $notification): ?array
    {
        if ($notification->source_type !== NotificationSourceType::Incident
            || ! is_numeric($notification->source_reference_id)
            || ! is_numeric($notification->payload_json['escalation_level'] ?? null)) {
            return null;
        }

        $incident = Incident::query()
            ->where('team_id', $notification->team_id)
            ->with('status')
            ->find((int) $notification->source_reference_id);

        if ($incident === null) {
            return null;
        }

        $terms = [
            'incident_id' => $incident->id,
            'acknowledged' => $incident->acknowledged_at !== null,
            'claimed' => $incident->claimed_by_user_id !== null,
            'terminal' => $incident->isTerminal(),
        ];

        return $terms['acknowledged'] || $terms['claimed'] || $terms['terminal'] ? $terms : null;
    }

    /**
     * Ventana anti-ruido entre avisos distintos del mismo incidente por canal
     * pagado (SMS, WhatsApp, voz) a la misma dirección. Más corta que el
     * reintento mínimo de un paso de la escalera (EscalationLadder), así que
     * nunca se come un re-aviso deliberado.
     */
    public const int PAID_COOLDOWN_SECONDS = 90;

    /** @var list<string> */
    private const array PAID_CHANNEL_TYPES = ['sms', 'whatsapp', 'voice'];

    /**
     * Términos del contacto reciente que activa la ventana, o null.
     *
     * @return array{incident_id: int, cooldown_seconds: int, seconds_ago: int, previous_notification_id: int}|null
     */
    private function recentPaidContact(Notification $notification, NotificationChannel $channel, NotificationRecipient $recipient, string $targetAddress): ?array
    {
        if (! in_array($channel->channel_type->value, self::PAID_CHANNEL_TYPES, true)
            || $notification->source_type !== NotificationSourceType::Incident
            || ! is_numeric($notification->source_reference_id)) {
            return null;
        }

        $since = now()->subSeconds(self::PAID_COOLDOWN_SECONDS);

        $previous = NotificationDelivery::query()
            ->where('team_id', $notification->team_id)
            ->where('notification_id', '!=', $notification->id)
            ->where('created_at', '>=', $since)
            ->whereNotIn('status', [DeliveryStatus::Failed, DeliveryStatus::Skipped, DeliveryStatus::Cancelled, DeliveryStatus::Bounced])
            ->whereHas('channel', fn ($query) => $query->whereIn('channel_type', self::PAID_CHANNEL_TYPES))
            ->whereHas('notification', fn ($query) => $query
                ->where('source_type', NotificationSourceType::Incident->value)
                ->where('source_reference_id', $notification->source_reference_id))
            ->whereHas('recipient', fn ($query) => $query->where(fn ($match) => $match
                ->where('phone', $targetAddress)
                ->orWhere('address', $targetAddress)
                ->when($recipient->recipient_reference_id !== null && $recipient->recipient_type->value === 'user', fn ($user) => $user
                    ->orWhere(fn ($same) => $same
                        ->where('recipient_type', 'user')
                        ->where('recipient_reference_id', $recipient->recipient_reference_id)))))
            ->latest('created_at')
            ->first();

        if ($previous === null || $previous->created_at === null) {
            return null;
        }

        return [
            'incident_id' => (int) $notification->source_reference_id,
            'cooldown_seconds' => self::PAID_COOLDOWN_SECONDS,
            'seconds_ago' => (int) $previous->created_at->diffInSeconds(now()),
            'previous_notification_id' => $previous->notification_id,
        ];
    }
}
