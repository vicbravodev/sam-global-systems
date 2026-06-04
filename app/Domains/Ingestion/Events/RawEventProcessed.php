<?php

namespace App\Domains\Ingestion\Events;

use App\Domains\Ingestion\Models\RawEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RawEventProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RawEvent $rawEvent,
    ) {}
}
