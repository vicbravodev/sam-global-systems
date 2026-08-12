<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class NotificationMeterSeeder extends Seeder
{
    public function run(): void
    {
        $meters = [
            'outbound_notifications' => [
                'name' => 'Outbound Notifications',
                'description' => 'Number of notification delivery attempts dispatched.',
            ],
            // Messaging channels bill per message (Twilio fee per channel);
            // codes must match ChannelType::usageMeterCode().
            'sms_messages' => [
                'name' => 'SMS Messages',
                'description' => 'Outbound SMS notification messages sent via Twilio.',
            ],
            'whatsapp_messages' => [
                'name' => 'WhatsApp Messages',
                'description' => 'Outbound WhatsApp notification messages sent via Twilio.',
            ],
            'voice_notification_calls' => [
                'name' => 'Voice Notification Calls',
                'description' => 'Outbound voice notification calls placed via Twilio (excludes incident DTMF verification calls, metered as voice_calls).',
            ],
        ];

        foreach ($meters as $code => $attributes) {
            UsageMeter::query()->updateOrCreate(
                ['code' => $code],
                [
                    ...$attributes,
                    'unit' => 'count',
                    'aggregation_type' => AggregationType::Sum,
                    'is_billable' => true,
                    'reset_period' => ResetPeriod::Monthly,
                ],
            );
        }
    }
}
