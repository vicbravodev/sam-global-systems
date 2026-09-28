<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessagingCharge>
 */
class MessagingChargeFactory extends Factory
{
    protected $model = MessagingCharge::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => 'twilio',
            'resource_type' => MessagingResourceType::Message,
            'provider_sid' => 'SM'.fake()->unique()->regexify('[a-f0-9]{32}'),
            'source_type' => MessagingChargeSource::Otp,
            'source_id' => null,
            'channel_type' => ChannelType::Sms,
            'status' => 'queued',
            'next_check_at' => now()->subMinute(),
            'check_attempts' => 0,
            'events_json' => [],
        ];
    }

    /**
     * Charge de una entrega de notificación: mismo tenant, mismo SID y tipo
     * de recurso que la entrega (§2.1 punto 9).
     */
    public function forDelivery(NotificationDelivery $delivery): static
    {
        $delivery->loadMissing('channel');
        $channelType = $delivery->channel->channel_type;

        return $this->state(fn () => [
            'team_id' => $delivery->team_id,
            'source_type' => MessagingChargeSource::NotificationDelivery,
            'source_id' => $delivery->id,
            'provider_sid' => $delivery->provider_message_id,
            'channel_type' => $channelType,
            'resource_type' => $channelType === ChannelType::Voice
                ? MessagingResourceType::Call
                : MessagingResourceType::Message,
        ]);
    }

    public function call(): static
    {
        return $this->state(fn () => [
            'resource_type' => MessagingResourceType::Call,
            'channel_type' => ChannelType::Voice,
            'provider_sid' => 'CA'.fake()->unique()->regexify('[a-f0-9]{32}'),
        ]);
    }
}
