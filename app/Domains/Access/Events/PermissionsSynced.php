<?php

namespace App\Domains\Access\Events;

use App\Domains\Access\Models\Role;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PermissionsSynced
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string>  $permissionCodes
     */
    public function __construct(
        public Role $role,
        public array $permissionCodes,
    ) {}
}
