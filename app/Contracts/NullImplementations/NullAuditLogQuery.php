<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\Audit\AuditLogQuery;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Returns an empty collection so analytics that read audit trails degrade
 * gracefully when no DB-backed query implementation is wired in.
 */
class NullAuditLogQuery implements AuditLogQuery
{
    public function forTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return collect();
    }
}
