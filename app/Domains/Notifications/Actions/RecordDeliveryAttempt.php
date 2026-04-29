<?php

namespace App\Domains\Notifications\Actions;

use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationDelivery;

class RecordDeliveryAttempt
{
    public function execute(NotificationDelivery $delivery, DeliveryResult $result): NotificationDelivery
    {
        $now = now();

        if ($result->success) {
            $delivery->fill([
                'status' => DeliveryStatus::Delivered,
                'provider_message_id' => $result->providerMessageId,
                'response_json' => $result->response,
                'sent_at' => $delivery->sent_at ?? $now,
                'delivered_at' => $now,
            ]);
        } else {
            $delivery->fill([
                'status' => DeliveryStatus::Failed,
                'response_json' => $result->response,
                'error_message' => $result->errorMessage,
                'failed_at' => $now,
            ]);
        }

        $delivery->save();

        return $delivery;
    }
}
