<?php

namespace App\Domains\Copilot\Data;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Models\Asset;

/**
 * Everything a data tool needs to answer: the tenant, what the user is
 * allowed to see, the asset in focus (if any) and the time window.
 */
final readonly class CopilotToolContext
{
    /**
     * @param  list<string>  $permissions
     * @param  array<string, mixed>  $arguments  Validated arguments the agent passed to the tool (empty on the deterministic path).
     */
    public function __construct(
        public int $teamId,
        public string $teamSlug,
        public array $permissions,
        public CopilotPeriod $period,
        public ?Asset $asset = null,
        public ?AssetCategory $category = null,
        public bool $isSuperAdmin = false,
        public array $arguments = [],
    ) {}

    public function can(string $permission): bool
    {
        return $this->isSuperAdmin || in_array($permission, $this->permissions, true);
    }
}
