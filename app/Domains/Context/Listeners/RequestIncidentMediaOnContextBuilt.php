<?php

namespace App\Domains\Context\Listeners;

use App\Domains\AI\Support\AIEvaluationGate;
use App\Domains\Context\Actions\AutoRequestIncidentMedia;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;

/**
 * Pull camera media for every event that can open an incident, as soon as its
 * context is built — so the AI and the operator see the cab and the road
 * instead of an empty gallery. An event can open an incident when it is an
 * emergency, critical, or one the AI evaluates (not in
 * `ai.skip_evaluation_categories` / `ai.skip_evaluation_event_types`; those
 * never reach the decision engine).
 *
 * The pull is quota-free (uploaded-media sweep, see
 * {@see AutoRequestIncidentMedia}) and idempotent, so a context rebuild never
 * re-requests. Opt-out per tenant via `media.auto_request_on_critical`.
 */
class RequestIncidentMediaOnContextBuilt
{
    public const string SETTING_KEY = 'media.auto_request_on_critical';

    public function __construct(
        private readonly AutoRequestIncidentMedia $autoRequestIncidentMedia,
        private readonly AIEvaluationGate $gate,
    ) {}

    public function handle(EventContextBuilt $event): void
    {
        $snapshot = $event->snapshot;

        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()
            ->with(['eventSeverity', 'eventCategory', 'eventType'])
            ->find($snapshot->normalized_event_id);

        if ($normalizedEvent === null) {
            SystemLog::skipped('context.media.auto_request_skipped', reason: 'normalized_event_missing', input: ['snapshot_id' => $snapshot->id]);

            return;
        }

        $severityCode = $normalizedEvent->eventSeverity?->code;
        $categoryCode = $normalizedEvent->eventCategory?->code;
        $typeCode = $normalizedEvent->eventType?->code;
        $gateSkipReason = $this->gate->skipReason($normalizedEvent);

        $incidentWorthy = $severityCode === 'critical'
            || NormalizeRawEvent::isEmergencyCode($categoryCode, $typeCode)
            || $gateSkipReason === null;

        if (! $incidentWorthy) {
            SystemLog::skipped('context.media.auto_request_skipped', reason: 'not_incident_worthy', input: [
                'normalized_event_id' => $normalizedEvent->id,
                'severity_code' => $severityCode,
                'category_code' => $categoryCode,
                'event_type_code' => $typeCode,
            ], calc: ['gate_skip_reason' => $gateSkipReason], debug: true);

            return;
        }

        $this->autoRequestIncidentMedia->execute($normalizedEvent, 'context_built');
    }
}
