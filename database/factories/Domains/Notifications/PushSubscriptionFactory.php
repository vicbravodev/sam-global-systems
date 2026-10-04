<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(40),
            'public_key' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth_token' => 'tBHItJI5svbpez7KI4CCXg',
            'content_encoding' => 'aes128gcm',
            'device_label' => 'Android · Chrome',
            'last_used_at' => null,
        ];
    }

    public function forMember(User $user, Team $team): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'team_id' => $team->id]);
    }
}
