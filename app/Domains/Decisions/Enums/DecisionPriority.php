<?php

namespace App\Domains\Decisions\Enums;

enum DecisionPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
    case Critical = 'critical';

    public function score(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 2,
            self::High => 3,
            self::Urgent => 4,
            self::Critical => 5,
        };
    }
}
