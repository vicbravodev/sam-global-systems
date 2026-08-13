<?php

namespace Tests\Unit\Domains\Notifications;

use App\Domains\Notifications\Enums\ChannelType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChannelTypeUsageMeterCodeTest extends TestCase
{
    /**
     * @return array<string, array{ChannelType, string}>
     */
    public static function meterCodeProvider(): array
    {
        return [
            'sms bills its own meter' => [ChannelType::Sms, 'sms_messages'],
            'whatsapp bills its own meter' => [ChannelType::Whatsapp, 'whatsapp_messages'],
            'voice bills its own meter' => [ChannelType::Voice, 'voice_notification_calls'],
            'email stays on the generic meter' => [ChannelType::Email, 'outbound_notifications'],
            'web stays on the generic meter' => [ChannelType::Web, 'outbound_notifications'],
            'push stays on the generic meter' => [ChannelType::Push, 'outbound_notifications'],
            'slack stays on the generic meter' => [ChannelType::Slack, 'outbound_notifications'],
            'webhook stays on the generic meter' => [ChannelType::Webhook, 'outbound_notifications'],
        ];
    }

    #[DataProvider('meterCodeProvider')]
    public function test_channel_type_maps_to_usage_meter_code(ChannelType $channelType, string $expected): void
    {
        $this->assertSame($expected, $channelType->usageMeterCode());
    }
}
