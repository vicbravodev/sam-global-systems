<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Incidents\Actions\AddIncidentEvidence;
use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Models\Incident;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Copy the GPS trail around an incident — `telematics.incident_trail_minutes`
 * before and after it was opened — into the incident's evidence, so the raw
 * points can be purged on schedule without an incident ever losing its route.
 *
 * Dispatched with a delay of that same window, so the "after" half has
 * already been recorded when it runs. Idempotent: an incident gets one trail.
 */
class FreezeIncidentLocationTrailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $incidentId,
        public readonly int $teamId,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(AddIncidentEvidence $addEvidence): void
    {
        // Lookup de entrada sin scope (así descubre su tenant) y verificación
        // de que concuerda con el team propagado. Ver §2.1 puntos 3 y 5.
        $incident = Incident::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->find($this->incidentId);

        if ($incident === null || $incident->asset_id === null) {
            return;
        }

        TenantContext::set($incident->team_id);

        $alreadyFrozen = $incident->evidence()
            ->where('evidence_type', EvidenceType::LocationTrail)
            ->exists();

        if ($alreadyFrozen) {
            return;
        }

        $window = (int) config('telematics.incident_trail_minutes', 30);

        $points = AssetLocationSnapshot::query()
            ->where('asset_id', $incident->asset_id)
            ->whereBetween('recorded_at', [
                $incident->opened_at->copy()->subMinutes($window),
                $incident->opened_at->copy()->addMinutes($window),
            ])
            ->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'speed', 'heading', 'recorded_at']);

        if ($points->isEmpty()) {
            return;
        }

        $addEvidence->execute(
            incident: $incident,
            evidenceType: EvidenceType::LocationTrail,
            sourceType: EvidenceSourceType::Telematics,
            title: "Recorrido ±{$window} min",
            description: "{$points->count()} puntos GPS alrededor de la apertura del incidente.",
            metadata: [
                'window_minutes' => $window,
                'points' => $points->map(fn (AssetLocationSnapshot $point) => [
                    'lat' => (float) $point->latitude,
                    'lng' => (float) $point->longitude,
                    'speed_kph' => $point->speed !== null ? (float) $point->speed : null,
                    'heading' => $point->heading,
                    'at' => $point->recorded_at->toIso8601ZuluString(),
                ])->all(),
            ],
        );
    }
}
