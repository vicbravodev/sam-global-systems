<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\Notification;
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
        $existing = Notification::query()
            ->where('team_id', $teamId)
            ->where('event_key', $eventKey)
            ->first();

        if ($existing) {
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
            return Notification::query()
                ->where('team_id', $teamId)
                ->where('event_key', $eventKey)
                ->firstOrFail();
        }

        if ($dispatchJob) {
            SendNotificationJob::dispatch($notification->id);
        }

        return $notification;
    }
}
