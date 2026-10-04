<?php

namespace App\Domains\Context\Listeners;

use App\Domains\Context\Actions\AutoRequestIncidentMedia;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Support\IncidentCreatedReaction;
use App\Domains\Incidents\Support\IsolatesIncidentCreatedReaction;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * Safety net: an incident opened from an event must never end up without
 * anyone asking the camera for its media, whatever opened it (AI decision,
 * emergency fast path, a rule) and whatever the context trigger decided.
 * When the event already has a media request (in any state) or media, the
 * context trigger handled it and this is a no-op.
 */
class RequestMediaOnIncidentCreated implements IncidentCreatedReaction
{
    use IsolatesIncidentCreatedReaction;

    public function __construct(
        private readonly AutoRequestIncidentMedia $autoRequestIncidentMedia,
    ) {}

    public function retryQueue(): string
    {
        return 'context';
    }

    public function react(IncidentCreated $event): void
    {
        $incident = $event->incident;
        $input = ['incident_id' => $incident->id, 'trigger' => 'incident_created'];

        if ($incident->related_event_id === null) {
            SystemLog::skipped('context.media.auto_request_skipped', reason: 'no_related_event', input: $input, debug: true);

            return;
        }

        TenantContext::for($incident->team_id, function () use ($incident, $input): void {
            $normalizedEvent = NormalizedEvent::query()->find($incident->related_event_id);

            if ($normalizedEvent === null) {
                SystemLog::skipped('context.media.auto_request_skipped', reason: 'normalized_event_missing', input: [
                    ...$input,
                    'normalized_event_id' => $incident->related_event_id,
                ]);

                return;
            }

            $alreadyRequested = EventMediaRequest::query()->where('normalized_event_id', $normalizedEvent->id)->exists()
                || EventMediaContext::query()->where('normalized_event_id', $normalizedEvent->id)->exists();

            if ($alreadyRequested) {
                SystemLog::skipped('context.media.auto_request_skipped', reason: 'already_requested', input: [
                    ...$input,
                    'normalized_event_id' => $normalizedEvent->id,
                ], debug: true);

                return;
            }

            $this->autoRequestIncidentMedia->execute($normalizedEvent, 'incident_created', ['incident_id' => $incident->id]);
        });
    }
}
