<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar counters (`navBadges` shared prop) are cached per tenant for a
 * minute. Anything that changes a counter forgets the entry once its
 * transaction commits, so the reload a socket event triggers shows the new
 * number instead of the cached one.
 */
class NavBadgeCache
{
    public const TTL_SECONDS = 60;

    public static function key(int $teamId): string
    {
        return "nav-badges:{$teamId}";
    }

    public static function forget(int $teamId): void
    {
        DB::afterCommit(fn () => Cache::forget(self::key($teamId)));
    }
}
