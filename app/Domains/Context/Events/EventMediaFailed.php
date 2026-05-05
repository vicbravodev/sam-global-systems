<?php

namespace App\Domains\Context\Events;

use App\Domains\Context\Models\EventMediaRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventMediaFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public EventMediaRequest $request,
        public string $reason,
    ) {}
}
