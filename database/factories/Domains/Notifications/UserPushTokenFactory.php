<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Models\UserPushToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserPushToken>
 */
class UserPushTokenFactory extends Factory
{
    protected $model = UserPushToken::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'platform' => fake()->randomElement(['ios', 'android', 'web']),
            'token' => 'fcm_'.(string) Str::uuid(),
            'device_name' => fake()->randomElement(['iPhone 15', 'Pixel 8', 'Chrome on Mac']),
            'last_used_at' => null,
        ];
    }

    public function ios(): static
    {
        return $this->state(fn () => ['platform' => 'ios']);
    }

    public function android(): static
    {
        return $this->state(fn () => ['platform' => 'android']);
    }

    public function web(): static
    {
        return $this->state(fn () => ['platform' => 'web']);
    }
}
