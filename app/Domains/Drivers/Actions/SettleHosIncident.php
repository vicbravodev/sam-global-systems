<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosNoticeCopy;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * The driver corrected an episode whose ladder already escalated (spec
 * 2026-10-04 §3.11): the incident gets a timeline line and, if nobody
 * acknowledged or claimed it yet and the driver has no other open escalated
 * episode on it (CreateIncidentFromEvent folds a driver's HOS events into one
 * incident, and the other episode may not be linked yet: unlinked ones count,
 * episodes linked to ANOTHER incident do not), it is resolved
 * `resolved_externally` — which also stops its escalation.
 *
 * A violation already happened: its incident is never closed by a
 * correction, only annotated (`violation_kept_open`) — whichever episode
 * settles it. An incident is a violation incident when any episode of the
 * team linked to it is a violation (resolved or not) or it carries a
 * `hos_limit_exceeded` event (main or folded): a break_due whose
 * `hos_unattended` folded into it never closes it either.
 *
 * The decision is re-checked on the incident locked for update, so an
 * operator who takes it (or a resolution) in the meantime always wins.
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
     * @return 'resolved'|'annotated'|'violation_kept_open'|'already_closed'|'already_settled'|'incident_not_found'
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

        // Otro episodio escalado y abierto del mismo chofer en ESTE incidente,
        // o aún sin vincular: CreateIncidentFromEvent pudo plegar su evento
        // aquí y el vínculo (LinkHosEpisodeIncident) llega después. Uno ya
        // vinculado a otro incidente no lo detiene.
        $otherOpenEpisodes = HosEpisode::query()
            ->where('team_id', $episode->team_id)
            ->where('driver_id', $episode->driver_id)
            ->whereNotNull('escalated_at')
            ->whereNull('resolved_at')
            ->whereKeyNot($episode->id)
            ->where(fn ($query) => $query->whereNull('incident_id')->orWhere('incident_id', $incident->id))
            ->exists();

        $calc = [
            'situation' => $episode->situation->value,
            'incident_id' => $incident->id,
            'acknowledged' => $incident->acknowledged_at !== null,
            'claimed' => $incident->claimed_by_user_id !== null,
            'terminal' => $incident->isTerminal(),
            'other_open_episodes' => $otherOpenEpisodes,
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

        // Línea y cierre juntos, sobre el incidente bloqueado: o queda todo o nada,
        // y si alguien lo tomó o cerró mientras tanto, gana esa persona.
        $outcome = DB::transaction(function () use ($incident, $episode, $otherOpenEpisodes, &$calc): string {
            $locked = Incident::query()
                ->where('team_id', $episode->team_id)
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->load('status')->isTerminal()) {
                $calc['terminal'] = true;

                return 'already_closed';
            }

            $calc['acknowledged'] = $locked->acknowledged_at !== null;
            $calc['claimed'] = $locked->claimed_by_user_id !== null;
            $violationIncident = $episode->situation === HosSituation::Violation
                || $this->isViolationIncident($locked, $episode->team_id);
            $calc['violation_incident'] = $violationIncident;

            $this->appendTimelineEntry->execute(
                incident: $locked,
                entryType: TimelineEntryType::ExternallyResolved,
                actorType: TimelineActorType::System,
                title: self::TIMELINE_TITLE,
                description: HosNoticeCopy::corrected($episode->situation),
                payload: ['hos_episode_id' => $episode->id, 'situation' => $episode->situation->value],
            );

            // La infracción ya ocurrió: corregirla no la borra, el equipo la revisa.
            if ($violationIncident) {
                return 'violation_kept_open';
            }

            if (IncidentSuppression::isUnderHumanControl($locked) || $otherOpenEpisodes) {
                return 'annotated';
            }

            $this->closeIncident->execute(
                incident: $locked,
                resolutionCode: ResolutionCode::ResolvedExternally,
                summary: 'Se resolvió solo: el chofer corrigió su situación de horas de servicio antes de que alguien tomara el incidente.',
                resolvedByType: IncidentCreatorType::System,
            );

            return 'resolved';
        });

        if ($outcome === 'already_closed') {
            SystemLog::skipped('hos.incident.settled', reason: 'already_closed', input: $input, calc: $calc);

            return 'already_closed';
        }

        SystemLog::ok('hos.incident.settled', input: $input, calc: $calc, result: ['outcome' => $outcome]);

        return $outcome;
    }

    /**
     * Any violation episode of the team linked to the incident (resolved or
     * not), or a `hos_limit_exceeded` event of the team as its main or a
     * folded event.
     */
    private function isViolationIncident(Incident $incident, int $teamId): bool
    {
        $violationEpisode = HosEpisode::query()
            ->where('team_id', $teamId)
            ->where('incident_id', $incident->id)
            ->where('situation', HosSituation::Violation)
            ->exists();

        if ($violationEpisode) {
            return true;
        }

        return NormalizedEvent::query()
            ->where('team_id', $teamId)
            ->whereHas('eventType', fn ($type) => $type->where('code', RaiseHosIncident::LIMIT_EXCEEDED_EVENT_TYPE))
            ->where(fn ($query) => $query
                ->whereKey($incident->related_event_id ?? 0)
                ->orWhereIn('id', IncidentEventLink::query()->where('incident_id', $incident->id)->select('normalized_event_id')))
            ->exists();
    }
}
