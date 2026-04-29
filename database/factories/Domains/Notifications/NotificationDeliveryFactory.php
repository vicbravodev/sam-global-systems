<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'recipient_id' => function (array $attributes) {
                return NotificationRecipient::factory()->create([
                    'notification_id' => $attributes['notification_id'],
                ])->id;
            },
            'channel_id' => function (array $attributes) {
                $teamId = Notification::withoutGlobalScopes()->find($attributes['notification_id'])->team_id;

                return NotificationChannel::factory()->create([
                    'team_id' => $teamId,
                ])->id;
            },
            'team_id' => fn (array $attributes) => Notification::withoutGlobalScopes()->find($attributes['notification_id'])->team_id,
            'provider_message_id' => null,
            'status' => DeliveryStatus::Pending,
            'attempt_number' => 1,
            'payload_json' => null,
            'response_json' => null,
            'error_message' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'failed_at' => null,
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'status' => DeliveryStatus::Delivered,
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => DeliveryStatus::Failed,
            'failed_at' => now(),
            'error_message' => 'Provider error',
        ]);
    }
}
