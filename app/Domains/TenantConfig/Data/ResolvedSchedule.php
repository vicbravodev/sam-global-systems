<?php

namespace App\Domains\TenantConfig\Data;

use DateTimeInterface;

/**
 * Result of resolving the tenant's active schedule profile against a given
 * point in time. Consumers in Automation/Notifications use this to decide
 * whether to suppress, route, or escalate based on operating context.
 */
final readonly class ResolvedSchedule
{
    /**
     * @param  array<string, mixed>|null  $afterHoursBehavior
     */
    public function __construct(
        public int $teamId,
        public string $profileCode,
        public string $timezone,
        public DateTimeInterface $evaluatedAt,
        public bool $withinOperatingHours,
        public ?array $afterHoursBehavior = null,
        public bool $isPersisted = false,
    ) {}
}
