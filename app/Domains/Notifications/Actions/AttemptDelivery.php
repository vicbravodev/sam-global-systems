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
use App\Support\LoggableCode;
use App\Support\SystemLog;

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

        $started = hrtime(true);
        $result = $this->drivers->driverFor($channel->channel_type)->send($rendered, $channel);
        $durationMs = SystemLog::elapsedMs($started);

        $this->recordAttempt->execute($delivery, $result);
        $delivery->refresh();

        // Nunca errorMessage (puede traer el número), response, address,
        // subject ni body: el fallo del proveedor sólo como código.
        $logInput = [
            'delivery_id' => $delivery->id,
            'notification_id' => $delivery->notification_id,
            'recipient_id' => $delivery->recipient_id,
            'channel_id' => $channel->id,
            'channel_type' => $channel->channel_type->value,
            'provider' => LoggableCode::guard($channel->provider),
            'stage' => match (true) {
                $delivery->fallback_from_delivery_id !== null => 'fallback',
                $delivery->attempt_number > 1 => 'retry',
                default => 'first',
            },
            'attempt_number' => $delivery->attempt_number,
        ];

        if ($result->success) {
            $chargeRecorded = $this->recordAcceptedResource($delivery, $channel->channel_type, $result);

            $usageMetered = $this->recordUsage->execute(
                teamId: $delivery->team_id,
                meterCode: $channel->channel_type->usageMeterCode(),
                quantity: $channel->channel_type === ChannelType::Sms ? max(1, (int) $result->segments) : 1,
                eventKey: $usageEventKey,
            );

            // "sent" = el proveedor aceptó. En Twilio la entrega real llega
            // después (awaiting_provider_confirmation, delivery_status queued).
            SystemLog::ok('notifications.delivery.sent', input: $logInput, result: [
                'delivery_status' => $delivery->status->value,
                'awaiting_provider_confirmation' => $result->awaitingProviderConfirmation,
                'provider_message_id' => LoggableCode::guard($result->providerMessageId),
                'provider_status' => LoggableCode::guard($result->providerStatus),
                'resource_type' => $result->resourceType?->value,
                'segments' => $result->segments,
                'charge_recorded' => $chargeRecorded,
                'usage_meter_code' => $channel->channel_type->usageMeterCode(),
                'usage_metered' => $usageMetered,
            ], durationMs: $durationMs);
        } else {
            // degraded: la cadena sigue con reintento o fallback; el fracaso
            // definitivo lo dicen fallback.exhausted y dispatch.completed.
            SystemLog::degraded('notifications.delivery.failed', reason: $result->permanent ? 'permanent_failure' : 'transient_failure', input: $logInput, result: [
                'delivery_status' => $delivery->status->value,
                'provider_error_code' => LoggableCode::guard($result->providerErrorCode),
                'permanent' => $result->permanent,
                'metered' => false,
            ], durationMs: $durationMs);
        }

        if ($refreshNotificationStatus && $delivery->notification !== null) {
            $this->refreshStatus->execute($delivery->notification);
        }

        if ($delivery->status === DeliveryStatus::Delivered) {
            NotificationDelivered::dispatch(
                $delivery->team_id,
                $delivery->notification_id,
                $delivery->id,
                $channel->channel_type->value,
            );
        } elseif ($delivery->status === DeliveryStatus::Failed) {
            NotificationFailed::dispatch(
                $delivery->team_id,
                $delivery->notification_id,
                $delivery->id,
                $channel->channel_type->value,
                $result->errorMessage ?? 'Unknown error',
            );
        }

        return $result;
    }

    /**
     * @return bool si quedó un cargo Twilio registrado para esta entrega.
     */
    private function recordAcceptedResource(NotificationDelivery $delivery, ChannelType $channelType, DeliveryResult $result): bool
    {
        if ($result->resourceType === null || $result->providerMessageId === null) {
            return false;
        }

        return $this->recordCharge->execute(
            teamId: $delivery->team_id,
            providerSid: $result->providerMessageId,
            resourceType: $result->resourceType,
            sourceType: MessagingChargeSource::NotificationDelivery,
            sourceId: $delivery->id,
            channelType: $channelType,
            status: $result->providerStatus,
            segments: $result->segments,
        ) !== null;
    }
}
