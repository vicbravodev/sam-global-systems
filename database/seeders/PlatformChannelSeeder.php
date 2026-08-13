<?php

namespace Database\Seeders;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use Illuminate\Database\Seeder;

/**
 * Canales de plataforma (team_id = null) que SAM opera para todos los tenants.
 * Sin credenciales en config_json: los drivers Twilio resuelven contra
 * services.twilio (env) vía PlatformTwilioConfig. Idempotente: sólo crea las
 * filas que falten y nunca pisa una existente (el operador puede haberla
 * ajustado desde la consola super-admin).
 */
class PlatformChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            ['code' => 'sam_email', 'name' => 'Email SAM', 'provider' => 'mail', 'channel_type' => ChannelType::Email],
            ['code' => 'sam_web', 'name' => 'Notificaciones en la app', 'provider' => 'soketi', 'channel_type' => ChannelType::Web],
            ['code' => 'sam_sms', 'name' => 'SMS SAM (Twilio)', 'provider' => 'twilio', 'channel_type' => ChannelType::Sms],
            ['code' => 'sam_whatsapp', 'name' => 'WhatsApp SAM (Twilio)', 'provider' => 'twilio', 'channel_type' => ChannelType::Whatsapp],
            ['code' => 'sam_voice', 'name' => 'Llamadas SAM (Twilio)', 'provider' => 'twilio', 'channel_type' => ChannelType::Voice],
        ];

        foreach ($channels as $channel) {
            $exists = NotificationChannel::query()
                ->whereNull('team_id')
                ->where(fn ($query) => $query
                    ->where('code', $channel['code'])
                    ->orWhere('channel_type', $channel['channel_type']))
                ->exists();

            if ($exists) {
                continue;
            }

            NotificationChannel::query()->create([
                'team_id' => null,
                'code' => $channel['code'],
                'name' => $channel['name'],
                'provider' => $channel['provider'],
                'channel_type' => $channel['channel_type'],
                'config_json' => null,
                'is_active' => true,
                'supports_priority' => false,
                'supports_template' => true,
            ]);
        }
    }
}
