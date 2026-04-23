<?php

namespace App\Domains\Context\Events;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventContextBuilt
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public EventContextSnapshot $snapshot,
        public OperationalContextProfile $profile,
    ) {}
}
