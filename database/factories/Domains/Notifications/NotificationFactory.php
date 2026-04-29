<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'source_type' => NotificationSourceType::Manual,
            'source_reference_id' => null,
            'notification_type' => 'incident.created',
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Pending,
            'subject' => 'Incident notification',
            'body_preview' => 'Preview body',
            'template_id' => null,
            'triggered_by_type' => NotificationTriggeredByType::System,
            'triggered_by_id' => null,
            'event_key' => 'manual:'.(string) Str::uuid(),
            'payload_json' => ['incident_type' => 'speeding', 'asset_name' => 'Truck-12'],
            'scheduled_at' => null,
            'sent_at' => null,
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => ['priority' => NotificationPriority::Critical]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => NotificationStatus::Pending]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);
    }
}
