<?php

namespace App\Contracts\TenantConfig;

use App\Domains\TenantConfig\Data\ResolvedSchedule;
use DateTimeInterface;

interface TenantScheduleResolver
{
    /**
     * Resolve whether the given moment is within the team's operating hours,
     * exposing the after-hours behavior block when applicable. When no
     * persisted schedule profile exists, returns a 24/7 default with no
     * after-hours behavior.
     */
    public function resolve(int $teamId, ?DateTimeInterface $at = null): ResolvedSchedule;
}
