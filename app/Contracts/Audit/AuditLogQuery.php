<?php

namespace App\Contracts\Audit;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads audit log entries scoped to a tenant and time window. Backed by
 * `App\Domains\Audit\Queries\DbAuditLogQuery`.
 */
interface AuditLogQuery
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection;
}
