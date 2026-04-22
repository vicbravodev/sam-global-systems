<?php

namespace App\Domains\Tenancy\Events;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Team $team,
        public User $owner,
    ) {}
}
