<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\ChannelType;

/**
 * SAM opera la mensajería central: las credenciales Twilio de plataforma viven
 * en config/services.php (env) y el `config_json` del canal actúa solo como
 * override puntual. El canal gana llave por llave; la plataforma rellena lo
 * que falte para que los tenants nunca configuren mensajería.
 */
final class PlatformTwilioConfig
{
    /**
     * @param  array<string, mixed>  $config  Channel `config_json`.
     * @return array<string, mixed>
     */
    public static function merge(array $config, ChannelType $type): array
    {
        $platform = (array) config('services.twilio', []);

        $defaults = [
            'twilio_account_sid' => $platform['account_sid'] ?? null,
            'twilio_auth_token' => $platform['auth_token'] ?? null,
            'from' => match ($type) {
                ChannelType::Sms => $platform['sms_from'] ?? null,
                ChannelType::Whatsapp => $platform['whatsapp_from'] ?? null,
                ChannelType::Voice => $platform['voice_from'] ?? null,
                default => null,
            },
        ];

        // Legacy alias keys the drivers also accept; a channel using the alias
        // must not be shadowed by a platform default under the canonical key.
        $aliases = [
            'twilio_account_sid' => 'account_sid',
            'twilio_auth_token' => 'auth_token',
        ];

        $merged = $config;

        foreach ($defaults as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            $current = $config[$key] ?? $config[$aliases[$key] ?? ''] ?? null;

            if (! is_string($current) || $current === '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
