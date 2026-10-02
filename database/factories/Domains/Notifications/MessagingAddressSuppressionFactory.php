<?php

namespace Database\Factories\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\MessagingAddressSuppression;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessagingAddressSuppression>
 */
class MessagingAddressSuppressionFactory extends Factory
{
    protected $model = MessagingAddressSuppression::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_type' => ChannelType::Sms,
            'address' => '+5215'.fake()->numerify('#########'),
            'reason' => 'opted_out',
            'provider_error_code' => '21610',
            'source' => 'provider_error',
            'suppressed_at' => now(),
        ];
    }
}
