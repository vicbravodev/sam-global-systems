<?php

namespace App\Support;

class OtpCacheKeys
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    /** Tope diario de SMS de OTP por usuario (cada SMS cuesta y se factura). */
    public const DAILY_PER_USER = 10;

    /** Tope diario de SMS de OTP por team: frena el abuso con muchas cuentas. */
    public const DAILY_PER_TEAM = 200;

    public static function forUser(int $userId): string
    {
        return "otp:phone:{$userId}";
    }

    public static function dailyForUser(int $userId): string
    {
        return "otp:daily:user:{$userId}:".now()->format('Ymd');
    }

    public static function dailyForTeam(int $teamId): string
    {
        return "otp:daily:team:{$teamId}:".now()->format('Ymd');
    }
}
