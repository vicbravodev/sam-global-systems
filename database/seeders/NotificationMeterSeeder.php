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
                'name' => 'Notificaciones enviadas',
                'description' => 'Number of notification deliveries successfully sent.',
            ],
            // Messaging channels bill per message (Twilio fee per channel);
            // codes must match ChannelType::usageMeterCode().
            'sms_messages' => [
                'name' => 'Mensajes SMS',
                'description' => 'Outbound SMS notification messages sent via Twilio.',
            ],
            'whatsapp_messages' => [
                'name' => 'Mensajes de WhatsApp',
                'description' => 'Outbound WhatsApp notification messages sent via Twilio.',
            ],
            'voice_notification_calls' => [
                'name' => 'Llamadas de aviso',
                'description' => 'Outbound voice notification calls placed via Twilio (excludes incident DTMF verification calls, metered as voice_calls).',
            ],
        ];

        // Real Twilio cost of every message/call SAM sends for the tenant
        // (notifications, OTP, verification calls), in micro-USD. Billed
        // cost-plus (see PlanSeeder / GenerateInvoiceSnapshotJob).
        $meters['messaging_cost_micros'] = [
            'name' => 'Mensajería y llamadas (Twilio)',
            'description' => 'Costo real de Twilio de los mensajes y llamadas enviados, en micro-USD.',
            'unit' => 'usd_micros',
        ];

        foreach ($meters as $code => $attributes) {
            UsageMeter::query()->updateOrCreate(
                ['code' => $code],
                [
                    'unit' => 'count',
                    ...$attributes,
                    'aggregation_type' => AggregationType::Sum,
                    'is_billable' => true,
                    'reset_period' => ResetPeriod::Monthly,
                ],
            );
        }
    }
}
