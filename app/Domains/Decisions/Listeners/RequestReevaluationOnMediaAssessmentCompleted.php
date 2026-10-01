<?php

namespace App\Domains\Decisions\Listeners;

use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\AI\Jobs\ReevaluateEventJob;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Models\Incident;
use App\Support\SystemLog;
use App\Support\TenantContext;

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

        // Toda evaluación persistida cuelga de un evento normalizado
        // (`normalized_event_id` NOT NULL). El listener corre dentro del
        // tenant de la evaluación: la decisión, el incidente y el job son
        // suyos. Ver §2.1.
        TenantContext::for($evaluation->team_id, fn () => $this->reopenPipeline($event, $evaluation));
    }

    private function reopenPipeline(MediaAssessmentCompleted $event, AIEventEvaluation $evaluation): void
    {
        // Inline media: no decision exists yet, so the upcoming engine run
        // already sees the assessment via the `media_assessment` fact.
        $decisionExists = Decision::query()
            ->where('ai_evaluation_id', $evaluation->id)
            ->exists();

        if (! $decisionExists) {
            SystemLog::skipped(
                'ai.reevaluation.not_requested',
                reason: 'decision_pending',
                input: ['evaluation_id' => $evaluation->id, 'normalized_event_id' => $evaluation->normalized_event_id],
            );

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
            SystemLog::skipped(
                'ai.reevaluation.not_requested',
                reason: 'media_already_assessed',
                input: ['evaluation_id' => $evaluation->id, 'normalized_event_id' => $evaluation->normalized_event_id],
                calc: [
                    'media_context_count' => $mediaContextIds->count(),
                    'assessed_elsewhere_count' => $assessedElsewhere->unique()->count(),
                ],
            );

            return;
        }

        // A terminally-closed incident is history; annotate (Incidents domain)
        // but never re-run the engine for it.
        $incident = Incident::query()
            ->where('related_event_id', $evaluation->normalized_event_id)
            ->orderByDesc('id')
            ->first();

        if ($incident !== null && $incident->isTerminal()) {
            SystemLog::skipped(
                'ai.reevaluation.not_requested',
                reason: 'incident_terminal',
                input: ['evaluation_id' => $evaluation->id, 'normalized_event_id' => $evaluation->normalized_event_id],
                result: ['incident_id' => $incident->id, 'incident_status_id' => $incident->incident_status_id],
            );

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
        $debounce = $this->debounceSeconds();

        // "Pedido", no "encolado": el job es único por (evento, trigger) y un
        // pedido puede absorberse en uno ya pendiente.
        SystemLog::ok(
            'ai.reevaluation.requested',
            input: [
                'normalized_event_id' => (int) $evaluation->normalized_event_id,
                'evaluation_id' => $evaluation->id,
                'trigger_type' => ReevaluationTrigger::MediaArrived->value,
                'trigger_reference_id' => $latest?->id,
                'requested_by' => 'media_assessment',
            ],
            calc: [
                'debounce_s' => $debounce,
                'new_media_count' => $mediaContextIds->diff($assessedElsewhere)->count(),
            ],
            result: ['latest_assessment_result' => $latest?->result?->value],
        );

        ReevaluateEventJob::dispatch(
            (int) $evaluation->normalized_event_id,
            ReevaluationTrigger::MediaArrived->value,
            $latest?->id,
            'Deferred media assessed: '.($latest?->result?->value ?? 'unknown'),
        )->delay($debounce);
    }

    private function debounceSeconds(): int
    {
        return max(0, (int) config('ai.reevaluation.media_debounce_seconds', 60));
    }
}
