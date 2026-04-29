<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\Audit\AuditLogQuery;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * SPEC-14-DEFERRED stand-in: returns an empty collection so analytics that
 * read audit trails can run before the Audit domain ships. The real
 * implementation lands with spec 14.
 */
class NullAuditLogQuery implements AuditLogQuery
{
    public function forTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return collect();
    }
}
