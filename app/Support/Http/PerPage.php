<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * Page size for list endpoints, clamped to [1, MAX]. An unbounded
 * `?per_page=` let a single request load a tenant's whole history (raw
 * payloads, audit, snapshots) into PHP memory.
 */
final class PerPage
{
    public const int MAX = 100;

    public static function from(Request $request, int $default): int
    {
        return min(max($request->integer('per_page', $default), 1), self::MAX);
    }
}
