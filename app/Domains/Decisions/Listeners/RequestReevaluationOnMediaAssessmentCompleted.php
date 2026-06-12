<?php

namespace App\Domains\Decisions\Listeners;

use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Models\Incident;

/**
 * Closes the multimodal loop (Roadmap B8): deferred camera footage lands
 * minutes after the decision engine already ran, so a fresh assessment must
 * re-open the pipeline — new evaluation version, new decision, and the
 * `media_assessment` fact finally influencing the real case.
 */
class RequestReevaluationOnMediaAssessmentCompleted
{
    public function handle(MediaAssessmentCompleted $event): void
    {
        $evaluation = $event->evaluation;

        if ($evaluation->normalized_event_id === null) {
            return;
        }

        // Inline media: no decision exists yet, so the upcoming engine run
        // already sees the assessment via the `media_assessment` fact.
        $decisionExists = Decision::withoutGlobalScopes()
            ->where('ai_evaluation_id', $evaluation->id)
            ->exists();

        if (! $decisionExists) {
            return;
        }

        // Re-assessing media that another evaluation version already scored
        // must not re-open the pipeline again — only genuinely new footage does.
        $mediaContextIds = $event->assessments->pluck('event_media_context_id')->filter()->unique();

        $assessedElsewhere = AIMediaAssessment::query()
            ->whereIn('event_media_context_id', $mediaContextIds)
            ->where('evaluation_id', '!=', $evaluation->id)
            ->pluck('event_media_context_id');

        if ($mediaContextIds->diff($assessedElsewhere)->isEmpty()) {
            return;
        }

        // A terminally-closed incident is history; annotate (Incidents domain)
        // but never re-run the engine for it.
        $incident = Incident::withoutGlobalScopes()
            ->where('related_event_id', $evaluation->normalized_event_id)
            ->orderByDesc('id')
            ->first();

        if ($incident !== null && $incident->isTerminal()) {
            return;
        }

        $latest = $event->assessments->last();

        // Burst guard: a panic can land a dozen-plus clips within a minute and
        // every assessed item fires this listener. The job is unique per
        // (event, trigger), so the first dispatch opens a short debounce window
        // and the rest collapse into it; the run that finally executes reads
        // every assessment present at that moment (DecisionFactsBuilder
        // aggregates across evaluation versions), so one re-evaluation — one
        // decision — reflects the whole burst instead of one per clip.
        ReevaluateEventJob::dispatch(
            (int) $evaluation->normalized_event_id,
            ReevaluationTrigger::MediaArrived->value,
            $latest?->id,
            'Deferred media assessed: '.($latest?->result?->value ?? 'unknown'),
        )->delay($this->debounceSeconds());
    }

    private function debounceSeconds(): int
    {
        return max(0, (int) config('ai.reevaluation.media_debounce_seconds', 60));
    }
}
