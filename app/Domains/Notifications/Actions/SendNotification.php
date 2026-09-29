<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SendNotification
{
    /**
     * Create a notification record (idempotent on team_id + event_key) and dispatch
     * the SendNotificationJob to fan out across recipients/channels.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(
        int $teamId,
        string $notificationType,
        NotificationSourceType $sourceType,
        ?string $sourceReferenceId,
        NotificationPriority $priority,
        NotificationTriggeredByType $triggeredByType,
        ?int $triggeredById,
        string $eventKey,
        array $payload = [],
        ?string $subject = null,
        ?string $bodyPreview = null,
        ?int $templateId = null,
        bool $dispatchJob = true,
    ): Notification {
        // Nunca event_key, subject, body_preview ni payload: el payload lleva
        // título del incidente, nombres de activo/chofer y ubicación.
        $logInput = [
            'notification_type' => LoggableCode::guard($notificationType),
            'source_type' => $sourceType->value,
        ];

        $existing = Notification::query()
            ->where('team_id', $teamId)
            ->where('event_key', $eventKey)
            ->first();

        if ($existing) {
            SystemLog::skipped('notifications.dedup.skipped', reason: 'event_key_exists', input: $logInput, result: ['existing_notification_id' => $existing->id]);

            return $existing;
        }

        try {
            // Savepoint: on PostgreSQL a failed INSERT aborts the enclosing
            // transaction, so without it the catch below could not query.
            $notification = DB::transaction(fn () => Notification::query()->create([
                'team_id' => $teamId,
                'source_type' => $sourceType,
                'source_reference_id' => $sourceReferenceId,
                'notification_type' => $notificationType,
                'priority' => $priority,
                'status' => NotificationStatus::Queued,
                'subject' => $subject,
                'body_preview' => $bodyPreview,
                'template_id' => $templateId,
                'triggered_by_type' => $triggeredByType,
                'triggered_by_id' => $triggeredById,
                'event_key' => $eventKey,
                'payload_json' => $payload,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Lost a race against a concurrent caller with the same event_key:
            // the unique (team_id, event_key) index kept the first row, which
            // already owns its dispatch.
            $winner = Notification::query()
                ->where('team_id', $teamId)
                ->where('event_key', $eventKey)
                ->firstOrFail();

            SystemLog::skipped('notifications.dedup.skipped', reason: 'event_key_race', input: $logInput, result: ['existing_notification_id' => $winner->id]);

            return $winner;
        }

        if ($dispatchJob) {
            SendNotificationJob::dispatch($notification->id);
        }

        // Los listeners la llaman dentro de transacciones ajenas: la línea sale
        // sólo si la fila sobrevive al commit más externo.
        $requestedInput = $logInput + [
            'source_reference_id' => LoggableCode::guard($sourceReferenceId),
            'priority' => $priority->value,
            'triggered_by_type' => $triggeredByType->value,
        ];
        $requestedCalc = [
            'explicit_recipients_count' => is_array($payload['recipients'] ?? null) ? count($payload['recipients']) : 0,
            'forced_channel_types' => self::forcedChannelTypes($payload['force_channels'] ?? null),
        ];
        $requestedResult = ['notification_id' => $notification->id, 'job_requested' => $dispatchJob];

        DB::afterCommit(fn () => SystemLog::ok('notifications.notification.requested', input: $requestedInput, calc: $requestedCalc, result: $requestedResult));

        return $notification;
    }

    /**
     * Los tipos de `force_channels` que son un canal real, o null si no se forzó.
     *
     * @return list<string>|null
     */
    private static function forcedChannelTypes(mixed $forced): ?array
    {
        if (! is_array($forced) || $forced === []) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (mixed $type): ?string => is_string($type) ? ChannelType::tryFrom($type)?->value : null,
            $forced,
        )));
    }
}
