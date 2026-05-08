<?php

namespace App\Domains\Notifications\Jobs;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Contracts\TenantConfig\TenantNotificationPoliciesResolver;
use App\Domains\Notifications\Actions\RecordDeliveryAttempt;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FallbackNotificationChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $failedDeliveryId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        ChannelDriverRegistry $drivers,
        TenantNotificationPoliciesResolver $policies,
        RenderNotificationContent $render,
        RecordDeliveryAttempt $recordAttempt,
        RecordUsageEvent $recordUsage,
    ): void {
        $primary = NotificationDelivery::withoutGlobalScopes()
            ->with(['notification.team', 'recipient', 'channel'])
            ->find($this->failedDeliveryId);

        if ($primary === null || ! $primary->notification || ! $primary->notification->team) {
            return;
        }

        $policy = $policies->resolve($primary->notification->team);

        $fallback = collect($policy->fallbackChannels)
            ->first(fn ($type) => $type->value !== $primary->channel->channel_type->value);

        if (! $fallback) {
            return;
        }

        $fallbackChannel = NotificationChannel::query()
            ->where(function ($q) use ($primary) {
                $q->where('team_id', $primary->team_id)->orWhereNull('team_id');
            })
            ->where('is_active', true)
            ->where('channel_type', $fallback->value)
            ->first();

        if (! $fallbackChannel) {
            return;
        }

        $exists = NotificationDelivery::withoutGlobalScopes()
            ->where('notification_id', $primary->notification_id)
            ->where('recipient_id', $primary->recipient_id)
            ->where('channel_id', $fallbackChannel->id)
            ->exists();

        if ($exists) {
            return;
        }

        $delivery = NotificationDelivery::query()->create([
            'notification_id' => $primary->notification_id,
            'recipient_id' => $primary->recipient_id,
            'channel_id' => $fallbackChannel->id,
            'team_id' => $primary->team_id,
            'status' => DeliveryStatus::Sending,
            'attempt_number' => 1,
            'sent_at' => now(),
        ]);

        $rendered = $render->execute(
            $primary->notification,
            $primary->recipient,
            $fallbackChannel->channel_type,
        );

        $result = $drivers->driverFor($fallbackChannel->channel_type)->send($rendered, $fallbackChannel);

        $recordAttempt->execute($delivery, $result);
        $delivery->refresh();

        $recordUsage->execute(
            teamId: $delivery->team_id,
            meterCode: 'outbound_notifications',
            quantity: 1,
            eventKey: "notif_fallback_{$delivery->id}",
        );

        if ($result->success) {
            NotificationDelivered::dispatch(
                $delivery->team_id,
                $delivery->notification_id,
                $delivery->id,
                $fallbackChannel->channel_type->value,
            );

            return;
        }

        NotificationFailed::dispatch(
            $delivery->team_id,
            $delivery->notification_id,
            $delivery->id,
            $fallbackChannel->channel_type->value,
            $result->errorMessage ?? 'Unknown error',
        );
    }
}
