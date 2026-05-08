<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Events\EventMediaAvailable;

class EvaluateMediaOnEventMediaAvailable
{
    public function handle(EventMediaAvailable $event): void
    {
        $evaluation = AIEventEvaluation::withoutGlobalScopes()
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
    }
}
