<?php

namespace App\Support;

class OtpCacheKeys
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public static function forUser(int $userId): string
    {
        return "otp:phone:{$userId}";
    }
}
