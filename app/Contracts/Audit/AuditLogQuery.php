<?php

namespace App\Contracts\Audit;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * SPEC-14-DEFERRED: Reads audit log entries scoped to a tenant and time window.
 *
 * Implemented by the Audit domain (spec 14) once it lands. Until then a Null
 * implementation returns an empty collection so analytics that read audit
 * trails (operational summary, change histories) can run in isolation.
 */
interface AuditLogQuery
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): Collection;
}
