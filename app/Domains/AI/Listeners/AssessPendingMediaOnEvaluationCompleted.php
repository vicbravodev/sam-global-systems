<?php

namespace App\Domains\AI\Listeners;

use App\Domains\AI\Events\AIEvaluationCompleted;
use App\Domains\AI\Jobs\EvaluateEventMediaJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventMediaContext;

/**
 * Guarantee every persisted media asset of an event gets a multimodal
 * assessment, regardless of arrival order. Media extraction and the text
 * evaluation run on separate queues, so a clip/still often persists *before*
 * the `AIEventEvaluation` exists — in that window `EvaluateMediaOnEventMediaAvailable`
 * has no evaluation to attach to and drops the asset silently. When the
 * evaluation finally completes, this listener sweeps any media of the event
 * that no evaluation version has assessed yet and enqueues it, so the footage
 * is always analyzed and its findings flow into the decision facts (real event
 * vs false positive) via `RequestReevaluationOnMediaAssessmentCompleted`.
 *
 * Re-evaluation versions are no-ops here: media already assessed under an
 * earlier version is filtered out, so a re-eval never re-bills the same asset.
 */
class AssessPendingMediaOnEvaluationCompleted
{
    public function handle(AIEvaluationCompleted $event): void
    {
        $evaluation = $event->evaluation;

        if ($evaluation->normalized_event_id === null) {
            return;
        }

        $eventId = (int) $evaluation->normalized_event_id;

        $assessedMediaIds = AIMediaAssessment::query()
            ->whereIn(
                'evaluation_id',
                AIEventEvaluation::withoutGlobalScopes()
                    ->where('normalized_event_id', $eventId)
                    ->select('id'),
            )
            ->pluck('event_media_context_id')
            ->all();

        $pendingMediaIds = EventMediaContext::withoutGlobalScopes()
            ->where('normalized_event_id', $eventId)
            ->when($assessedMediaIds !== [], fn ($query) => $query->whereNotIn('id', $assessedMediaIds))
            ->pluck('id')
            ->all();

        if ($pendingMediaIds === []) {
            return;
        }

        EvaluateEventMediaJob::dispatch($evaluation->id, $pendingMediaIds);
    }
}
