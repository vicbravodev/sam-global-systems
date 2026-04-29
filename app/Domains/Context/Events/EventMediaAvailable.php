<?php

namespace App\Domains\Context\Events;

use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventMediaAvailable
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public EventMediaContext $media,
        public NormalizedEvent $normalizedEvent,
    ) {}
}
