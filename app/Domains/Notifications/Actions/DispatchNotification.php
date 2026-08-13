<?php

namespace App\Domains\Notifications\Actions;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Notifications\Data\RecipientDescriptor;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Events\NotificationCreated;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Events\NotificationPushedBroadcast;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchNotification
{
    public function __construct(
        private readonly ResolveRecipients $resolveRecipients,
        private readonly SelectNotificationChannels $selectChannels,
        private readonly RenderNotificationContent $render,
        private readonly RecordDeliveryAttempt $recordAttempt,
        private readonly ChannelDriverRegistry $drivers,
        private readonly RecordUsageEvent $recordUsage,
        private readonly IssueNotificationReplyToken $issueReplyToken,
    ) {}

    public function execute(Notification $notification): Notification
    {
        $descriptors = $this->resolveRecipients->execute($notification);

        if ($descriptors === []) {
            $notification->update(['status' => NotificationStatus::Cancelled]);

            return $notification;
        }

        $recipientsExisted = NotificationRecipient::withoutGlobalScopes()
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

        $deliveredCount = 0;
        $failedCount = 0;
        $totalAttempts = 0;

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

                $totalAttempts++;

                $delivery = $this->createDeliveryOrSkip($notification, $recipient, $channel);

                if ($delivery === null) {
                    continue;
                }

                $rendered = $this->render->execute($notification, $recipient, $channel->channel_type, null, $targetAddress);
                $rendered = $this->appendReplyInstructions($notification, $recipient, $rendered);

                $driver = $this->drivers->driverFor($channel->channel_type);

                $delivery->update([
                    'status' => DeliveryStatus::Sending,
                    'sent_at' => now(),
                    'payload_json' => [
                        'address' => $rendered->address,
                        'subject' => $rendered->subject,
                        'body' => $rendered->body,
                    ],
                ]);

                $result = $driver->send($rendered, $channel);

                $this->recordAttempt->execute($delivery, $result);

                $delivery->refresh();

                $this->recordUsage->execute(
                    teamId: $notification->team_id,
                    meterCode: $channel->channel_type->usageMeterCode(),
                    quantity: 1,
                    eventKey: "notif_delivery_{$delivery->id}",
                );

                if ($result->success) {
                    $deliveredCount++;
                    $this->afterSuccessfulDelivery($notification, $recipient, $delivery, $channel->channel_type);
                } else {
                    $failedCount++;
                    NotificationFailed::dispatch(
                        $notification->team_id,
                        $notification->id,
                        $delivery->id,
                        $channel->channel_type->value,
                        $result->errorMessage ?? 'Unknown error',
                    );
                }
            }
        }

        $this->finalizeStatus($notification, $deliveredCount, $failedCount, $totalAttempts);

        return $notification->refresh();
    }

    /**
     * Critical incident SMS/WhatsApp carry a reply token (Roadmap B9) so the
     * operator can confirm/dismiss/escalate by answering the message. The SMS
     * body is pre-fitted so the driver's 160-char truncation never eats the
     * instructions.
     */
    private function appendReplyInstructions(
        Notification $notification,
        NotificationRecipient $recipient,
        RenderedNotification $rendered,
    ): RenderedNotification {
        if (! in_array($rendered->channelType, [ChannelType::Sms, ChannelType::Whatsapp], true)) {
            return $rendered;
        }

        if ($notification->source_type !== NotificationSourceType::Incident
            || ! is_numeric($notification->source_reference_id)
            || ! $notification->priority->isCritical()) {
            return $rendered;
        }

        $token = $this->issueReplyToken->execute(
            $notification,
            $recipient,
            $rendered->channelType,
            (int) $notification->source_reference_id,
        );

        $instructions = "\nResponde SI-{$token->token} confirma / NO-{$token->token} descarta / ESC-{$token->token} escala";

        $body = $rendered->body;

        if ($rendered->channelType === ChannelType::Sms) {
            $maxBase = 160 - mb_strlen($instructions);

            if (mb_strlen($body) > $maxBase) {
                $body = mb_substr($body, 0, $maxBase - 1).'…';
            }
        }

        return new RenderedNotification(
            channelType: $rendered->channelType,
            address: $rendered->address,
            subject: $rendered->subject,
            body: $body.$instructions,
            variables: $rendered->variables,
            recipientName: $rendered->recipientName,
        );
    }

    private function recordSkippedDelivery(
        Notification $notification,
        NotificationRecipient $recipient,
        NotificationChannel $channel,
        string $reason,
    ): void {
        try {
            DB::transaction(function () use ($notification, $recipient, $channel, $reason) {
                $existing = NotificationDelivery::withoutGlobalScopes()
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
            Log::warning('Failed to record skipped notification delivery', [
                'notification_id' => $notification->id,
                'recipient_id' => $recipient->id,
                'channel_id' => $channel->id,
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
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
        $existing = NotificationRecipient::withoutGlobalScopes()
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
                $existing = NotificationDelivery::withoutGlobalScopes()
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

    private function afterSuccessfulDelivery(
        Notification $notification,
        NotificationRecipient $recipient,
        NotificationDelivery $delivery,
        ChannelType $channelType,
    ): void {
        NotificationDelivered::dispatch(
            $notification->team_id,
            $notification->id,
            $delivery->id,
            $channelType->value,
        );

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
        ));
    }

    private function finalizeStatus(
        Notification $notification,
        int $deliveredCount,
        int $failedCount,
        int $totalAttempts,
    ): void {
        if ($totalAttempts === 0) {
            $notification->update(['status' => NotificationStatus::Cancelled]);

            return;
        }

        $status = match (true) {
            $deliveredCount > 0 && $failedCount === 0 => NotificationStatus::Sent,
            $deliveredCount > 0 && $failedCount > 0 => NotificationStatus::PartiallySent,
            default => NotificationStatus::Failed,
        };

        $notification->update([
            'status' => $status,
            'sent_at' => $deliveredCount > 0 ? now() : $notification->sent_at,
        ]);
    }
}
