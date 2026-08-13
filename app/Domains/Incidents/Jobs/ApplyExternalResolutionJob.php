<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\ApplyExternalResolution;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
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

        if ($event === null || ($event->payload_normalized_json['is_resolved'] ?? null) !== true) {
            return;
        }

        // Trabaja dentro del tenant del propio registro: el lookup de
        // entrada no puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($event->team_id);

        foreach ($this->findOpenIncidents($event) as $incident) {
            $applyExternalResolution->execute($incident, $event);
        }
    }

    /**
     * Open incidents the resolution update applies to. Primary match: incidents
     * linked to an earlier normalized event sharing the provider event id (the
     * original panic). Fallback: open incidents for the same asset/driver inside
     * the incident dedup window, mirroring CreateIncidentFromEvent.
     *
     * @return list<Incident>
     */
    private function findOpenIncidents(NormalizedEvent $event): array
    {
        $externalEventId = $event->rawEvent()->withoutGlobalScopes()->value('external_event_id');

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
                return $incidents->all();
            }
        }

        if ($event->asset_id === null && $event->driver_id === null) {
            return [];
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

        return $fallback->all();
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'normalized_event_id' => $this->normalizedEventId,
        ]);
    }
}
