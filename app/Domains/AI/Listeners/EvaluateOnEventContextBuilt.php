<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Normalization\Models\NormalizedEvent;

class EvaluateOnEventContextBuilt
{
    public function __construct(
        private readonly AIEvaluationGate $gate,
    ) {}

    public function handle(EventContextBuilt $event): void
    {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()
            ->with('eventCategory')
            ->find($event->snapshot->normalized_event_id);

        if ($normalizedEvent === null || ! $this->gate->shouldEvaluate($normalizedEvent)) {
            return;
        }

        EvaluateEventJob::dispatch($normalizedEvent->id);
    }
}
