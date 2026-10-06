<?php

namespace App\Domains\Assets\Enums;

/**
 * The provider feeds SAM follows. Samsara caps a stats request at three stat
 * types, so the tracked stats are split in feeds with their own cursor: what
 * changes by the second (position, engine, fuel), what drifts slowly, and
 * the trailers' position (a separate resource at the provider), which feeds
 * the tractor–trailer coupling.
 */
enum TelematicsFeed: string
{
    case Motion = 'motion';
    case Diagnostics = 'diagnostics';
    case Trailers = 'trailers';

    public function intervalSeconds(): int
    {
        return match ($this) {
            self::Motion => (int) config('telematics.interval_seconds'),
            self::Diagnostics => (int) config('telematics.diagnostics_interval_seconds'),
            self::Trailers => (int) config('telematics.trailers_interval_seconds'),
        };
    }
}
