<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRecipient>
 */
class NotificationRecipientFactory extends Factory
{
    protected $model = NotificationRecipient::class;

    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'team_id' => fn (array $attributes) => Notification::withoutGlobalScopes()->find($attributes['notification_id'])->team_id,
            'recipient_type' => RecipientType::User,
            'recipient_reference_id' => null,
            'name' => fake()->name(),
            'address' => fake()->safeEmail(),
            'channel_preference' => null,
            'role' => null,
            'metadata_json' => null,
        ];
    }

    public function externalContact(): static
    {
        return $this->state(fn () => [
            'recipient_type' => RecipientType::ExternalContact,
        ]);
    }
}
