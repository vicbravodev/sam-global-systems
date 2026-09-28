<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\ChannelType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Horario de silencio de un tenant (`TenantNotificationPolicy.quiet_hours_json`)
 * o de un usuario (`NotificationPreference.quiet_hours_json`).
 *
 * Formato: `{"start": "22:00", "end": "07:00", "timezone": "America/Mexico_City"}`.
 * `timezone` es opcional (cae a la zona del team y luego a la de la app);
 * `enabled: false` lo desactiva. Una ventana que cruza medianoche
 * (start > end) se respeta.
 *
 * Dentro de la ventana, las prioridades no críticas no salen por canales que
 * despiertan a alguien y cuestan dinero (SMS/WhatsApp/voz); la app y el correo
 * siguen.
 */
final class QuietHours
{
    /** @var array<int, ChannelType> */
    public const array SILENCED_CHANNELS = [ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice];

    /**
     * @param  array<string, mixed>|null  $quietHours
     */
    public static function isActive(?array $quietHours, ?string $fallbackTimezone, ?CarbonInterface $now = null): bool
    {
        if ($quietHours === null || ($quietHours['enabled'] ?? true) === false) {
            return false;
        }

        $start = self::minutes($quietHours['start'] ?? null);
        $end = self::minutes($quietHours['end'] ?? null);

        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        $timezone = self::timezone($quietHours['timezone'] ?? null)
            ?? self::timezone($fallbackTimezone)
            ?? (string) config('app.timezone', 'UTC');

        $local = Carbon::instance($now ?? now())->setTimezone($timezone);
        $current = $local->hour * 60 + $local->minute;

        return $start < $end
            ? $current >= $start && $current < $end
            : $current >= $start || $current < $end;
    }

    public static function silences(ChannelType $channelType): bool
    {
        return in_array($channelType, self::SILENCED_CHANNELS, true);
    }

    private static function minutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    private static function timezone(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            new \DateTimeZone($value);

            return $value;
        } catch (Throwable) {
            return null;
        }
    }
}
