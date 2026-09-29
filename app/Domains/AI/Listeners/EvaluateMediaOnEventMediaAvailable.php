<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Events\EventMediaAvailable;
use App\Support\SystemLog;
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
                // Solo afirma que no hubo evaluación a la cual adjuntarla; no
                // promete un barrido posterior (en eventos que el gate salta
                // no la evalúa nadie).
                SystemLog::skipped('ai.media.assessment_deferred', reason: 'no_evaluation_yet', input: [
                    'normalized_event_id' => $event->normalizedEvent->id,
                    'event_media_context_id' => $event->media->id,
                ]);

                return;
            }

            EvaluateEventMediaJob::dispatch(
                $evaluation->id,
                [$event->media->id],
            )->afterCommit();
        });
    }
}
