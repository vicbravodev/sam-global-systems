<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\ApplyExternalResolution;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ApplyExternalResolutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly int $normalizedEventId,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(ApplyExternalResolution $applyExternalResolution): void
    {
        $event = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        $input = ['normalized_event_id' => $this->normalizedEventId];

        if ($event === null) {
            SystemLog::skipped('incidents.external_resolution.matched', reason: 'event_missing', input: $input);

            return;
        }

        if (($event->payload_normalized_json['is_resolved'] ?? null) !== true) {
            SystemLog::skipped('incidents.external_resolution.matched', reason: 'not_resolved', input: $input);

            return;
        }

        PipelineTrace::adopt($event->trace_id, $event->team_id, ['normalized_event_id' => $event->id]);

        // Trabaja dentro del tenant del propio registro: el lookup de
        // entrada no puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($event->team_id);

        $match = $this->findOpenIncidents($event);

        if ($match['incidents'] === []) {
            SystemLog::skipped('incidents.external_resolution.matched', reason: 'no_open_incident', input: $input, calc: [
                'strategy' => 'none',
                'external_event_id_present' => $match['external_event_id_present'],
                'window_minutes' => $match['window_minutes'],
            ]);

            return;
        }

        SystemLog::ok('incidents.external_resolution.matched',
            input: $input,
            calc: ['strategy' => $match['strategy'], 'window_minutes' => $match['window_minutes']],
            result: [
                'incident_ids' => array_map(fn (Incident $incident) => $incident->id, $match['incidents']),
                'incidents_count' => count($match['incidents']),
            ],
        );

        foreach ($match['incidents'] as $incident) {
            $applyExternalResolution->execute($incident, $event);
        }
    }

    /**
     * Open incidents the resolution update applies to. Primary match: incidents
     * linked to an earlier normalized event sharing the provider event id (the
     * original panic). Fallback: open incidents for the same asset/driver inside
     * the incident dedup window, mirroring CreateIncidentFromEvent.
     *
     * A provider entity (a safety event, `provider_event_key`) is updated in
     * place, so its incident is linked to THIS row (`same_event`); rows from
     * before the entity still match by provider event id. It never falls back
     * to the asset window: a harsh braking dismissed at Samsara must not mark
     * an unrelated open panic of the same truck as resolved.
     *
     * @return array{incidents: list<Incident>, strategy: 'same_event'|'external_event_id'|'asset_window'|'none', external_event_id_present: bool, window_minutes: ?int}
     */
    private function findOpenIncidents(NormalizedEvent $event): array
    {
        $externalEventId = $event->rawEvent()->withoutGlobalScopes()->value('external_event_id');
        $externalEventIdPresent = $externalEventId !== null;

        if ($event->provider_event_key !== null) {
            $incidents = Incident::query()
                ->where('team_id', $event->team_id)
                ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
                ->where(fn ($q) => $q
                    ->where('related_event_id', $event->id)
                    ->orWhereHas('eventLinks', fn ($links) => $links->where('normalized_event_id', $event->id)))
                ->get();

            if ($incidents->isNotEmpty()) {
                return [
                    'incidents' => array_values($incidents->all()),
                    'strategy' => 'same_event',
                    'external_event_id_present' => $externalEventIdPresent,
                    'window_minutes' => null,
                ];
            }
        }

        if ($externalEventId !== null) {
            $incidents = Incident::query()
                ->where('team_id', $event->team_id)
                ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
                ->whereHas('eventLinks.normalizedEvent.rawEvent', function ($q) use ($event, $externalEventId) {
                    $q->where('external_event_id', $externalEventId)
                        ->where('id', '!=', $event->raw_event_id);
                })
                ->get();

            if ($incidents->isNotEmpty()) {
                return [
                    'incidents' => array_values($incidents->all()),
                    'strategy' => 'external_event_id',
                    'external_event_id_present' => true,
                    'window_minutes' => null,
                ];
            }
        }

        if ($event->provider_event_key !== null || ($event->asset_id === null && $event->driver_id === null)) {
            return ['incidents' => [], 'strategy' => 'none', 'external_event_id_present' => $externalEventIdPresent, 'window_minutes' => null];
        }

        $window = (int) config('incidents.duplicate_window_minutes', 30);
        $occurredAt = $event->occurred_at ?? now();

        $fallback = Incident::query()
            ->where('team_id', $event->team_id)
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->where('opened_at', '>=', Carbon::instance($occurredAt)->subMinutes($window))
            ->where(function ($q) use ($event) {
                if ($event->asset_id !== null) {
                    $q->orWhere('asset_id', $event->asset_id);
                }
                if ($event->driver_id !== null) {
                    $q->orWhere('driver_id', $event->driver_id);
                }
            })
            ->orderByDesc('opened_at')
            ->limit(1)
            ->get();

        return [
            'incidents' => array_values($fallback->all()),
            'strategy' => $fallback->isNotEmpty() ? 'asset_window' : 'none',
            'external_event_id_present' => $externalEventIdPresent,
            'window_minutes' => $window,
        ];
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'normalized_event_id' => $this->normalizedEventId,
        ]);
    }
}
