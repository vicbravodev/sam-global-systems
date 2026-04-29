<?php

namespace App\Domains\Notifications\Enums;

enum NotificationPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';

    public function isCritical(): bool
    {
        return $this === self::Critical;
    }

    public function suppressedByMute(): bool
    {
        return $this === self::Low || $this === self::Normal;
    }
}
