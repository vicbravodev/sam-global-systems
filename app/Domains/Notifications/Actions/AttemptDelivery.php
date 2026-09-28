<?php

namespace App\Domains\Notifications\Actions;

use App\Contracts\Notifications\ChannelDriverRegistry;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Events\NotificationDelivered;
use App\Domains\Notifications\Events\NotificationFailed;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;

/**
 * Un intento de envío de una entrega, común al primer envío, al reintento y
 * al fallback:
 *
 *   1. guarda el payload exacto (dirección/asunto/cuerpo) — el reintento lo
 *      reenvía tal cual;
 *   2. llama al driver y persiste el resultado;
 *   3. si el proveedor aceptó: registra el recurso Twilio facturable y mide
 *      el uso del canal — NUNCA si el envío falló;
 *   4. recalcula el estado de la notificación y emite Delivered/Failed.
 *
 * Debe llamarse dentro del TenantContext de la entrega.
 */
class AttemptDelivery
{
    public function __construct(
        private readonly ChannelDriverRegistry $drivers,
        private readonly RecordDeliveryAttempt $recordAttempt,
        private readonly RecordMessagingCharge $recordCharge,
        private readonly RecordMessagingUsage $recordUsage,
        private readonly RefreshNotificationStatus $refreshStatus,
    ) {}

    public function execute(
        NotificationDelivery $delivery,
        NotificationChannel $channel,
        RenderedNotification $rendered,
        string $usageEventKey,
        bool $refreshNotificationStatus = true,
    ): DeliveryResult {
        $delivery->update([
            'status' => DeliveryStatus::Sending,
            'sent_at' => $delivery->sent_at ?? now(),
            'payload_json' => [
                'address' => $rendered->address,
                'subject' => $rendered->subject,
                'body' => $rendered->body,
            ],
        ]);

        $result = $this->drivers->driverFor($channel->channel_type)->send($rendered, $channel);

        $this->recordAttempt->execute($delivery, $result);
        $delivery->refresh();

        if ($result->success) {
            $this->recordAcceptedResource($delivery, $channel->channel_type, $result);

            $this->recordUsage->execute(
                teamId: (int) $delivery->team_id,
                meterCode: $channel->channel_type->usageMeterCode(),
                quantity: $channel->channel_type === ChannelType::Sms ? max(1, (int) $result->segments) : 1,
                eventKey: $usageEventKey,
            );
        }

        if ($refreshNotificationStatus && $delivery->notification !== null) {
            $this->refreshStatus->execute($delivery->notification);
        }

        if ($delivery->status === DeliveryStatus::Delivered) {
            NotificationDelivered::dispatch(
                (int) $delivery->team_id,
                (int) $delivery->notification_id,
                (int) $delivery->id,
                $channel->channel_type->value,
            );
        } elseif ($delivery->status === DeliveryStatus::Failed) {
            NotificationFailed::dispatch(
                (int) $delivery->team_id,
                (int) $delivery->notification_id,
                (int) $delivery->id,
                $channel->channel_type->value,
                $result->errorMessage ?? 'Unknown error',
            );
        }

        return $result;
    }

    private function recordAcceptedResource(NotificationDelivery $delivery, ChannelType $channelType, DeliveryResult $result): void
    {
        if ($result->resourceType === null || $result->providerMessageId === null) {
            return;
        }

        $this->recordCharge->execute(
            teamId: (int) $delivery->team_id,
            providerSid: $result->providerMessageId,
            resourceType: $result->resourceType,
            sourceType: MessagingChargeSource::NotificationDelivery,
            sourceId: (int) $delivery->id,
            channelType: $channelType,
            status: $result->providerStatus,
            segments: $result->segments,
        );
    }
}
