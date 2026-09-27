<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Support\TenantContext;

class EvaluateMediaOnEventMediaAvailable
{
    public function handle(EventMediaAvailable $event): void
    {
        // Todo el listener corre dentro del tenant del evento: la evaluación a
        // buscar y el job a despachar son suyos. Ver §2.1.
        TenantContext::for($event->normalizedEvent->team_id, function () use ($event) {
            $evaluation = AIEventEvaluation::query()
                ->where('normalized_event_id', $event->normalizedEvent->id)
                ->orderByDesc('evaluation_version')
                ->first();

            if ($evaluation === null) {
                return;
            }

            EvaluateEventMediaJob::dispatch(
                $evaluation->id,
                [$event->media->id],
            );
        });
    }
}
