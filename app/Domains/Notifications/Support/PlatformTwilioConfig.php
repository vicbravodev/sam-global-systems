<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\ChannelType;

/**
 * SAM opera la mensajería con UNA cuenta Twilio de plataforma: las
 * credenciales viven sólo en config/services.php (env TWILIO_*), nunca en la
 * base de datos ni por tenant. El `config_json` de un canal de plataforma
 * sólo puede ajustar valores no secretos (remitente, plantilla de WhatsApp,
 * timeout de timbrado); cualquier otra llave se ignora.
 */
final class PlatformTwilioConfig
{
    /**
     * Llaves no secretas que un canal Twilio puede sobreescribir.
     *
     * @var list<string>
     */
    public const ALLOWED_CHANNEL_KEYS = ['from', 'content_sid', 'ring_timeout_seconds'];

    public static function accountSid(): ?string
    {
        return self::string(config('services.twilio.account_sid'));
    }

    public static function authToken(): ?string
    {
        return self::string(config('services.twilio.auth_token'));
    }

    /**
     * ¿Se puede hablar con Twilio? En sandbox no hace falta cuenta real.
     */
    public static function hasCredentials(): bool
    {
        return TwilioSandbox::enabled()
            || (self::accountSid() !== null && self::authToken() !== null);
    }

    /**
     * Configuración efectiva (no secreta) de un canal Twilio: los overrides
     * permitidos del canal y, si el canal no fija remitente, el de plataforma.
     *
     * @param  array<string, mixed>  $channelConfig  `config_json` del canal.
     * @return array{from: string|null, content_sid: string|null, ring_timeout_seconds: int|null}
     */
    public static function resolve(array $channelConfig, ChannelType $type): array
    {
        $platformFrom = match ($type) {
            ChannelType::Sms => config('services.twilio.sms_from'),
            ChannelType::Whatsapp => config('services.twilio.whatsapp_from'),
            ChannelType::Voice => config('services.twilio.voice_from'),
            default => null,
        };

        $timeout = $channelConfig['ring_timeout_seconds'] ?? null;

        return [
            'from' => self::string($channelConfig['from'] ?? null) ?? self::string($platformFrom),
            'content_sid' => self::string($channelConfig['content_sid'] ?? null),
            'ring_timeout_seconds' => is_numeric($timeout) ? (int) $timeout : null,
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
