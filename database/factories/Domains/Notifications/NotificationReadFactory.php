<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationRead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationRead>
 */
class NotificationReadFactory extends Factory
{
    protected $model = NotificationRead::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'notification_id' => Notification::factory(),
            'user_id' => User::factory(),
            'read_at' => now(),
        ];
    }
}
