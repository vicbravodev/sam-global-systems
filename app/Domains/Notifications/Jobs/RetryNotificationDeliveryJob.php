<?php

namespace App\Domains\Notifications\Jobs;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Notifications\Actions\RecordDeliveryAttempt;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Enums\ChannelType;
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

    public int $timeout = 120;

    public function __construct(
        public readonly int $deliveryId,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Per-channel attempt count. Webhook caps at 3 retries (PR #2a policy);
     * everyone else keeps the spec-13 default of 5.
     */
    public function tries(): int
    {
        return $this->isWebhook() ? 3 : 5;
    }

    /**
     * Per-channel exponential backoff. Webhook follows the spec-13 PR #2 policy
     * (30s / 2min / 10min, 3 attempts); other channels keep the default ramp.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        if ($this->isWebhook()) {
            return [30, 120, 600];
        }

        return [30, 60, 120, 300, 600];
    }

    private function isWebhook(): bool
    {
        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->with('channel')
            ->find($this->deliveryId);

        return $delivery?->channel?->channel_type === ChannelType::Webhook;
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

        $result = $drivers->driverFor($delivery->channel->channel_type)->send($rendered, $delivery->channel);

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
