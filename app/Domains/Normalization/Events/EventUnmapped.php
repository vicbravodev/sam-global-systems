<?php

namespace App\Domains\Normalization\Events;

use App\Domains\Ingestion\Models\RawEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventUnmapped
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RawEvent $rawEvent,
        public readonly string $externalEventType,
        public readonly int $providerId,
    ) {}
}
