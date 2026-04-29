<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'code' => 'email_default_'.fake()->unique()->numerify('####'),
            'name' => 'Default Email Channel',
            'provider' => 'mail',
            'channel_type' => ChannelType::Email,
            'config_json' => null,
            'is_active' => true,
            'supports_priority' => false,
            'supports_template' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel_type' => ChannelType::Email,
            'provider' => 'mail',
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel_type' => ChannelType::Sms,
            'provider' => 'twilio',
        ]);
    }

    public function web(): static
    {
        return $this->state(fn () => [
            'channel_type' => ChannelType::Web,
            'provider' => 'soketi',
        ]);
    }

    public function push(): static
    {
        return $this->state(fn () => [
            'channel_type' => ChannelType::Push,
            'provider' => 'firebase',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
