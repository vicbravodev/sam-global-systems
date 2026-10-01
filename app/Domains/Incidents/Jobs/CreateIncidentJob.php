<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Actions\ApplyReevaluationToIncident;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Actions\RequestIncidentReview;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Ingestion\Actions\AlertPipelineFailure;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateIncidentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $normalizedEventId,
        public readonly array $context = [],
    ) {
        $this->onQueue('incidents');
    }

    public function uniqueId(): string
    {
        return 'incident:'.$this->normalizedEventId;
    }

    public function handle(
        CreateIncidentFromEvent $createIncidentFromEvent,
        ?ApplyReevaluationToIncident $applyReevaluation = null,
        ?RequestIncidentReview $requestReview = null,
    ): void {
        $applyReevaluation ??= app(ApplyReevaluationToIncident::class);

        $event = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($event === null) {
            SystemLog::skipped('incidents.creation.skipped', reason: 'event_missing', input: ['normalized_event_id' => $this->normalizedEventId]);

            return;
        }

        PipelineTrace::adopt($event->trace_id, $event->team_id, ['normalized_event_id' => $event->id]);

        // Trabaja dentro del tenant del propio registro: el lookup de
        // entrada no puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($event->team_id);

        // Reevaluación de un evento que ya tiene incidente (v2+ de la IA):
        // se actualiza ese incidente en vez de abrir otro. Cubre también los
        // eventos sin activo/conductor, que el dedup por ventana no agrupa.
        $existing = $applyReevaluation->findExistingFor($event);

        if ($existing !== null) {
            $decision = isset($this->context['decision_id'])
                ? Decision::query()->where('team_id', $event->team_id)->find((int) $this->context['decision_id'])
                : null;

            SystemLog::ok('incidents.creation.routed_to_existing',
                input: ['normalized_event_id' => $event->id, 'decision_id' => $this->context['decision_id'] ?? null],
                result: ['incident_id' => $existing->id, 'decision_found' => $decision !== null],
            );

            if ($decision !== null) {
                $applyReevaluation->execute($existing, $decision);
            }

            return;
        }

        $incident = $createIncidentFromEvent->execute($event, $this->context);

        $decisionId = $this->context['decision_id'] ?? null;
        $notFlaggedReason = $this->flagForReview($incident, $requestReview ?? app(RequestIncidentReview::class));

        if ($notFlaggedReason === null) {
            SystemLog::ok('incidents.review.flagged', input: ['incident_id' => $incident->id, 'decision_id' => $decisionId]);
        } elseif ($notFlaggedReason !== 'not_review_decision') {
            SystemLog::skipped('incidents.review.flagged', reason: $notFlaggedReason, input: ['incident_id' => $incident->id, 'decision_id' => $decisionId]);
        }

        SystemLog::ok('incidents.auto_assign.requested', input: ['incident_id' => $incident->id], result: ['job_requested' => true]);

        AutoAssignIncidentJob::dispatch($incident->id);
    }

    /**
     * A REQUIRE_HUMAN_REVIEW decision passes `request_review`: the incident it
     * just opened moves to in-review so the inbox surfaces it for triage.
     * Only the incident this decision created is touched — when the event was
     * linked to an already-open incident (dedup) that one keeps its status.
     *
     * @return string|null motivo de no marcar, o null si marcó
     */
    private function flagForReview(Incident $incident, RequestIncidentReview $requestReview): ?string
    {
        $reason = $this->context['request_review'] ?? null;
        $decisionId = $this->context['decision_id'] ?? null;

        if (! is_string($reason) || $decisionId === null) {
            return 'not_review_decision';
        }

        if ((int) $incident->related_decision_id !== (int) $decisionId
            || (int) $incident->related_event_id !== $this->normalizedEventId
        ) {
            return 'linked_to_other_incident';
        }

        if ($incident->status?->code !== IncidentStatusCode::Open->value) {
            return 'not_open';
        }

        $requestReview->execute($incident, $reason, IncidentCreatorType::System);

        return null;
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'normalized_event_id' => $this->normalizedEventId,
        ]);

        app(AlertPipelineFailure::class)->forJobFailure(static::class, $exception, normalizedEventId: $this->normalizedEventId);
    }
}
