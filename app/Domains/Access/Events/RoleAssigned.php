<?php

namespace App\Domains\Access\Events;

use App\Domains\Access\Models\Role;
use App\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoleAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Membership $membership,
        public Role $role,
    ) {}
}
