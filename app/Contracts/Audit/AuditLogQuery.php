<?php

namespace App\Contracts\Audit;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads audit log entries scoped to a tenant and time window. Until the Audit
 * domain ships a DB-backed query implementation, this contract is fulfilled by
 * `NullAuditLogQuery` returning an empty collection.
 */
interface AuditLogQuery
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection;
}
