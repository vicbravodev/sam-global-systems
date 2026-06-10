<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationReplyToken;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationReplyToken>
 */
class NotificationReplyTokenFactory extends Factory
{
    protected $model = NotificationReplyToken::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'incident_id' => Incident::factory(),
            'notification_id' => null,
            'user_id' => null,
            'channel_type' => ChannelType::Whatsapp,
            'address' => '+5215512345678',
            'token' => Str::upper(Str::random(6)),
            'expires_at' => now()->addDay(),
            'consumed_at' => null,
            'consumed_action' => null,
            'reply_payload_json' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }

    public function consumed(string $action = 'acknowledge'): static
    {
        return $this->state(fn () => [
            'consumed_at' => now()->subMinutes(5),
            'consumed_action' => $action,
        ]);
    }
}
