<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationPreference;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => null,
            'role' => null,
            'notification_type' => 'incident.created',
            'allowed_channels_json' => [ChannelType::Email->value, ChannelType::Web->value],
            'muted' => false,
            'quiet_hours_json' => null,
            'escalation_fallback_json' => null,
        ];
    }

    public function muted(): static
    {
        return $this->state(fn () => ['muted' => true]);
    }
}
