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
            ->with(['eventCategory', 'eventType'])
            ->find($event->snapshot->normalized_event_id);

        if ($normalizedEvent === null || ! $this->gate->allows($normalizedEvent, 'context_listener')) {
            return;
        }

        // El job se despacha dentro del tenant del evento para que viaje en su
        // contexto hasta el worker. Ver §2.1. El closure es de bloque a propósito:
        // PendingDispatch encola al destruirse y debe hacerlo DENTRO del tenant,
        // no después de que TenantContext::for() restaure el contexto previo.
        // afterCommit: EventContextBuilt se emite dentro de la transacción de
        // BuildEventContext; sin él el worker puede no ver aún el snapshot.
        TenantContext::for(
            $normalizedEvent->team_id,
            function () use ($normalizedEvent): void {
                EvaluateEventJob::dispatch($normalizedEvent->id)->afterCommit();
            },
        );
    }
}
