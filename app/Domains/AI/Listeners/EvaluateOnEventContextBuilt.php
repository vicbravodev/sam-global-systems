<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\EvaluateEventJob;
use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\TenantContext;

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

        // El job se despacha dentro del tenant del evento para que viaje en su
        // contexto hasta el worker. Ver §2.1.
        TenantContext::for(
            $normalizedEvent->team_id,
            fn () => EvaluateEventJob::dispatch($normalizedEvent->id),
        );
    }
}
