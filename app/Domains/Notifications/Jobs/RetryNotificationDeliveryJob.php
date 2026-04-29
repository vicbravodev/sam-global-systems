<?php

namespace App\Domains\Notifications\Jobs;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Notifications\Actions\RecordDeliveryAttempt;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryNotificationDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        public readonly int $deliveryId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        ChannelDriverRegistry $drivers,
        RenderNotificationContent $render,
        RecordDeliveryAttempt $recordAttempt,
        RecordUsageEvent $recordUsage,
    ): void {
        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->with(['notification', 'recipient', 'channel'])
            ->find($this->deliveryId);

        if ($delivery === null || $delivery->status === DeliveryStatus::Delivered) {
            return;
        }

        $delivery->update([
            'status' => DeliveryStatus::Sending,
            'attempt_number' => $delivery->attempt_number + 1,
        ]);

        $rendered = $render->execute(
            $delivery->notification,
            $delivery->recipient,
            $delivery->channel->channel_type,
        );

        $result = $drivers->driverFor($delivery->channel->channel_type)->send($rendered);

        $recordAttempt->execute($delivery, $result);
        $delivery->refresh();

        $recordUsage->execute(
            teamId: $delivery->team_id,
            meterCode: 'outbound_notifications',
            quantity: 1,
            eventKey: "notif_retry_{$delivery->id}_{$delivery->attempt_number}",
        );

        if ($result->success) {
            NotificationDelivered::dispatch(
                $delivery->team_id,
                $delivery->notification_id,
                $delivery->id,
                $delivery->channel->channel_type->value,
            );

            return;
        }

        NotificationFailed::dispatch(
            $delivery->team_id,
            $delivery->notification_id,
            $delivery->id,
            $delivery->channel->channel_type->value,
            $result->errorMessage ?? 'Unknown error',
        );
    }
}
