<?php

namespace App\Domains\Notifications\Actions;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
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

class DispatchNotification
{
    public function __construct(
        private readonly ResolveRecipients $resolveRecipients,
        private readonly SelectNotificationChannels $selectChannels,
        private readonly RenderNotificationContent $render,
        private readonly RecordDeliveryAttempt $recordAttempt,
        private readonly ChannelDriverRegistry $drivers,
        private readonly RecordUsageEvent $recordUsage,
    ) {}

    public function execute(Notification $notification): Notification
    {
        $descriptors = $this->resolveRecipients->execute($notification);

        if ($descriptors === []) {
            $notification->update(['status' => NotificationStatus::Cancelled]);

            return $notification;
        }

        $recipients = [];

        foreach ($descriptors as $descriptor) {
            $recipients[] = NotificationRecipient::query()->create([
                'notification_id' => $notification->id,
                'team_id' => $notification->team_id,
                'recipient_type' => $descriptor->recipientType,
                'recipient_reference_id' => $descriptor->referenceId,
                'name' => $descriptor->name,
                'address' => $descriptor->address,
                'channel_preference' => $descriptor->channelPreference,
                'role' => $descriptor->role,
                'metadata_json' => $descriptor->metadata,
            ]);
        }

        NotificationCreated::dispatch(
            $notification->team_id,
            $notification->id,
            $notification->notification_type,
            count($recipients),
        );

        $deliveredCount = 0;
        $failedCount = 0;
        $totalAttempts = 0;

        foreach ($recipients as $recipient) {
            $channels = $this->selectChannels->execute($notification, $recipient);

            foreach ($channels as $channel) {
                $totalAttempts++;

                $delivery = $this->createDeliveryOrSkip($notification, $recipient, $channel);

                if ($delivery === null) {
                    continue;
                }

                $rendered = $this->render->execute($notification, $recipient, $channel->channel_type);

                $driver = $this->drivers->driverFor($channel->channel_type);

                $delivery->update([
                    'status' => DeliveryStatus::Sending,
                    'sent_at' => now(),
                    'payload_json' => [
                        'subject' => $rendered->subject,
                        'body' => $rendered->body,
                    ],
                ]);

                $result = $driver->send($rendered);

                $this->recordAttempt->execute($delivery, $result);

                $delivery->refresh();

                $this->recordUsage->execute(
                    teamId: $notification->team_id,
                    meterCode: 'outbound_notifications',
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
