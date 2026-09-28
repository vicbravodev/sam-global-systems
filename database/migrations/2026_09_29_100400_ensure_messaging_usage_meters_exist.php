<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los meters de mensajería deben existir aunque nadie haya corrido los
     * seeders: un meter ausente hacía fallar la medición DESPUÉS de un envío
     * real, y el reintento de cola volvía a enviar. Insert idempotente; los
     * seeders siguen siendo dueños de nombres/descripciones (updateOrCreate).
     */
    private const METERS = [
        'outbound_notifications' => ['Outbound Notifications', 'count'],
        'sms_messages' => ['SMS Messages', 'count'],
        'whatsapp_messages' => ['WhatsApp Messages', 'count'],
        'voice_notification_calls' => ['Voice Notification Calls', 'count'],
        'otp_sms_sent' => ['OTP SMS Sent', 'count'],
        'voice_calls' => ['Voice Calls', 'count'],
        'messaging_cost_micros' => ['Messaging Provider Cost', 'usd_micros'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::METERS as $code => [$name, $unit]) {
            DB::table('usage_meters')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'description' => null,
                'unit' => $unit,
                'aggregation_type' => 'sum',
                'is_billable' => true,
                'reset_period' => 'monthly',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Sin rollback de datos: los meters pueden tener eventos de uso
        // asociados y los seeders también los crean.
    }
};
