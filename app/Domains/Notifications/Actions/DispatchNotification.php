<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\RecipientDescriptor;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Events\NotificationCreated;
use App\Domains\Notifications\Events\NotificationPushedBroadcast;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Notifications\Support\CancelBlockedNotification;
use App\Domains\Notifications\Support\ChannelAddress;
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

        $descriptors = $this->resolveRecipients->execute($notification);

        if ($descriptors === []) {
            $notification->update(['status' => NotificationStatus::Cancelled]);

            return $notification;
        }

        $recipientsExisted = NotificationRecipient::query()
            ->where('notification_id', $notification->id)
            ->exists();

        $recipients = [];

        foreach ($descriptors as $descriptor) {
            $recipients[] = $this->firstOrCreateRecipient($notification, $descriptor);
        }

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

        foreach ($recipients as $recipient) {
            $channels = $this->selectChannels->execute($notification, $recipient);

            foreach ($channels as $channel) {
                $targetAddress = $recipient->addressForChannel($channel->channel_type);

                if ($targetAddress === null || $targetAddress === '') {
                    $this->recordSkippedDelivery(
                        $notification,
                        $recipient,
                        $channel,
                        "no {$channel->channel_type->value} address (missing phone/email) for recipient",
                    );

                    continue;
                }

                // A phone channel fed an email (or vice versa) is a billable
                // send doomed to fail at the provider: skip it with a reason.
                if (($invalid = ChannelAddress::invalidReason($channel->channel_type, $targetAddress)) !== null) {
                    $this->recordSkippedDelivery($notification, $recipient, $channel, $invalid);

                    continue;
                }

                $delivery = $this->createDeliveryOrSkip($notification, $recipient, $channel);

                if ($delivery === null) {
                    continue;
                }

                $rendered = $this->render->execute($notification, $recipient, $channel->channel_type, null, $targetAddress);
                $rendered = $this->appendReplyInstructions->execute($notification, $recipient, $rendered);

                $result = $this->attemptDelivery->execute(
                    $delivery,
                    $channel,
                    $rendered,
                    usageEventKey: "notif_delivery_{$delivery->id}",
                    refreshNotificationStatus: false,
                );

                if ($result->success) {
                    $this->broadcastWebDelivery($notification, $recipient, $channel->channel_type);
                }
            }
        }

        $this->refreshStatus->execute($notification);

        return $notification->refresh();
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

                if ($existing) {
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

    private function createDeliveryOrSkip(
        Notification $notification,
        NotificationRecipient $recipient,
        NotificationChannel $channel,
    ): ?NotificationDelivery {
        try {
            return DB::transaction(function () use ($notification, $recipient, $channel) {
                $existing = NotificationDelivery::query()
                    ->where('notification_id', $notification->id)
                    ->where('recipient_id', $recipient->id)
                    ->where('channel_id', $channel->id)
                    ->first();

                if ($existing) {
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
        } catch (\Throwable) {
            return null;
        }
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
            teamId: (int) $notification->team_id,
        ));
    }
}
