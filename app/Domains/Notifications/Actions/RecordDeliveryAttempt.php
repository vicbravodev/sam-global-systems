<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationDelivery;

/**
 * Persiste el resultado inmediato de un intento de envío.
 *
 * - Canal síncrono con éxito → `Delivered`.
 * - Proveedor que confirma después (Twilio) → `Queued` + `accepted_at`: la
 *   API aceptó el envío, NO que llegó. El estado final lo fijan el status
 *   callback o el reconciliador ({@see ApplyTwilioStatusUpdate}).
 * - Resultado incierto (timeout) → sigue `Sending` con `provider_status`
 *   `uncertain`, sin NotificationFailed.
 * - Fallo → `Failed`, con el código de proveedor y si es permanente.
 *
 * Cada intento limpia el feedback del intento anterior (un reintento es un
 * SID nuevo con su propio ciclo de estados).
 */
class RecordDeliveryAttempt
{
    /** `provider_status` de una entrega con resultado incierto. */
    public const string UNCERTAIN = 'uncertain';

    public function execute(NotificationDelivery $delivery, DeliveryResult $result): NotificationDelivery
    {
        $now = now();

        $reset = [
            'provider_status' => null,
            'provider_error_code' => null,
            'permanent_failure' => false,
            'accepted_at' => null,
            'read_at' => null,
            'answered_at' => null,
            'call_duration_seconds' => null,
            'segments' => null,
            'last_provider_event_at' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'error_message' => null,
        ];

        if ($result->success && $result->awaitingProviderConfirmation) {
            $delivery->fill([
                ...$reset,
                'status' => DeliveryStatus::Queued,
                'provider_message_id' => $result->providerMessageId,
                'provider_status' => $result->providerStatus,
                'segments' => $result->segments,
                'response_json' => $result->response,
                'sent_at' => $delivery->sent_at ?? $now,
                'accepted_at' => $now,
            ]);
        } elseif ($result->success) {
            $delivery->fill([
                ...$reset,
                'status' => DeliveryStatus::Delivered,
                'provider_message_id' => $result->providerMessageId,
                'response_json' => $result->response,
                'sent_at' => $delivery->sent_at ?? $now,
                'delivered_at' => $now,
            ]);
        } elseif ($result->uncertain) {
            // Ni aceptado ni fallido: sigue en vuelo hasta que
            // ResolveUncertainDeliveryJob lo encuentre (o no) en el proveedor.
            $delivery->fill([
                ...$reset,
                'status' => DeliveryStatus::Sending,
                'provider_message_id' => null,
                'provider_status' => self::UNCERTAIN,
                'response_json' => $result->response,
                'error_message' => $result->errorMessage,
            ]);
        } else {
            $delivery->fill([
                ...$reset,
                'status' => DeliveryStatus::Failed,
                'provider_message_id' => null,
                'provider_error_code' => $result->providerErrorCode,
                'permanent_failure' => $result->permanent,
                'response_json' => $result->response,
                'error_message' => $result->errorMessage,
                'failed_at' => $now,
            ]);
        }

        $delivery->save();

        return $delivery;
    }
}
