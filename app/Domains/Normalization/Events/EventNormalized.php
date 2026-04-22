<?php

namespace App\Domains\Normalization\Events;

use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventNormalized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NormalizedEvent $normalizedEvent,
    ) {}
}
