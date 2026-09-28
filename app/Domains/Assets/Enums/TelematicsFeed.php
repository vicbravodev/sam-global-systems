<?php

namespace App\Domains\Assets\Enums;

/**
 * The provider feeds SAM follows. Samsara caps a stats request at three stat
 * types, so the tracked stats are split in two feeds with their own cursor:
 * what changes by the second (position, engine, fuel) and what drifts slowly.
 */
enum TelematicsFeed: string
{
    case Motion = 'motion';
    case Diagnostics = 'diagnostics';

    public function intervalSeconds(): int
    {
        return match ($this) {
            self::Motion => (int) config('telematics.interval_seconds'),
            self::Diagnostics => (int) config('telematics.diagnostics_interval_seconds'),
        };
    }
}
