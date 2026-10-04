<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosNoticeCopy;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * The driver corrected an episode whose ladder already escalated (spec
 * 2026-10-04 §3.11): the incident gets a timeline line and, if nobody
 * acknowledged or claimed it yet and the driver has no other open escalated
 * episode (CreateIncidentFromEvent folds a driver's HOS events into one
 * incident, and the other episode may not be linked yet),
 * it is resolved `resolved_externally` — which also stops its escalation.
 *
 * Idempotent: the timeline line carries the episode id, so settling the same
 * episode again adds nothing.
 *
 * Must run inside the episode's TenantContext.
 */
class SettleHosIncident
{
    public const string TIMELINE_TITLE = 'El chofer ya corrigió';

    public function __construct(
        private readonly LinkHosEpisodeIncident $linkIncident,
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly CloseIncident $closeIncident,
    ) {}

    /**
     * @return 'resolved'|'annotated'|'already_closed'|'already_settled'|'incident_not_found'
     */
    public function execute(HosEpisode $episode): string
    {
        $input = ['team_id' => $episode->team_id, 'episode_id' => $episode->id, 'driver_id' => $episode->driver_id];
        $incident = $this->linkIncident->execute($episode);

        if ($incident === null) {
            // El pipeline aún no lo crea (o se descartó): nada que cerrar.
            SystemLog::skipped('hos.incident.settled', reason: 'incident_not_found', input: $input, calc: [
                'situation' => $episode->situation->value,
            ]);

            return 'incident_not_found';
        }

        $incident->loadMissing('status');

        $calc = [
            'situation' => $episode->situation->value,
            'incident_id' => $incident->id,
            'acknowledged' => $incident->acknowledged_at !== null,
            'claimed' => $incident->claimed_by_user_id !== null,
            'terminal' => $incident->isTerminal(),
            // Cualquier otro episodio escalado y abierto del mismo chofer, esté o
            // no vinculado ya: CreateIncidentFromEvent pudo plegar su evento en
            // este incidente y el vínculo (LinkHosEpisodeIncident) llega después.
            'other_open_episodes' => HosEpisode::query()
                ->where('team_id', $episode->team_id)
                ->where('driver_id', $episode->driver_id)
                ->whereNotNull('escalated_at')
                ->whereNull('resolved_at')
                ->whereKeyNot($episode->id)
                ->exists(),
        ];

        if ($calc['terminal']) {
            SystemLog::skipped('hos.incident.settled', reason: 'already_closed', input: $input, calc: $calc);

            return 'already_closed';
        }

        // El incidente ya pertenece al team del episodio (LinkHosEpisodeIncident
        // y attachIncident lo filtran); la línea de tiempo cuelga de él.
        $alreadySettled = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::ExternallyResolved)
            ->where('payload_json->hos_episode_id', $episode->id)
            ->exists();

        if ($alreadySettled) {
            SystemLog::skipped('hos.incident.settled', reason: 'already_settled', input: $input, calc: $calc);

            return 'already_settled';
        }

        $close = ! $calc['acknowledged'] && ! $calc['claimed'] && ! $calc['other_open_episodes'];

        // Línea y cierre juntos: o queda todo o nada.
        DB::transaction(function () use ($incident, $episode, $close): void {
            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::ExternallyResolved,
                actorType: TimelineActorType::System,
                title: self::TIMELINE_TITLE,
                description: HosNoticeCopy::corrected($episode->situation),
                payload: ['hos_episode_id' => $episode->id, 'situation' => $episode->situation->value],
            );

            if ($close) {
                $this->closeIncident->execute(
                    incident: $incident,
                    resolutionCode: ResolutionCode::ResolvedExternally,
                    summary: 'Se resolvió solo: el chofer corrigió su situación de horas de servicio antes de que alguien tomara el incidente.',
                    resolvedByType: IncidentCreatorType::System,
                );
            }
        });

        $outcome = $close ? 'resolved' : 'annotated';

        SystemLog::ok('hos.incident.settled', input: $input, calc: $calc, result: ['outcome' => $outcome]);

        return $outcome;
    }
}
