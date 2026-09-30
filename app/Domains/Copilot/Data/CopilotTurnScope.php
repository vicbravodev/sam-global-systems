<?php

namespace App\Domains\Copilot\Data;

use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Who is asking in this Copilot turn: the tenant, what the user may read and
 * the clock every tool shares. Built on the server; the model never supplies
 * the team, the user or the permissions.
 */
final readonly class CopilotTurnScope
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public int $teamId,
        public string $teamSlug,
        public array $permissions,
        public bool $isSuperAdmin,
        public string $timezone,
        public CarbonImmutable $now,
    ) {}

    /**
     * @param  list<string>  $permissions
     */
    public static function fromTeam(Team $team, array $permissions, bool $isSuperAdmin): self
    {
        return new self(
            (int) $team->id,
            (string) $team->slug,
            array_values($permissions),
            $isSuperAdmin,
            $team->timezone ?: (string) config('app.timezone'),
            CarbonImmutable::now(),
        );
    }

    public function can(?string $permission): bool
    {
        return $permission === null || $this->isSuperAdmin || in_array($permission, $this->permissions, true);
    }
}
