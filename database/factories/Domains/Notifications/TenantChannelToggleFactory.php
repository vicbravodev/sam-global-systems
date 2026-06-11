<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\TenantChannelToggle;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantChannelToggle>
 */
class TenantChannelToggleFactory extends Factory
{
    protected $model = TenantChannelToggle::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'notification_channel_id' => NotificationChannel::factory()->state(['team_id' => null]),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
