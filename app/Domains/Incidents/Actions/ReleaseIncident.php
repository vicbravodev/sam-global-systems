<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ReleaseIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly ArmIncidentEscalation $armIncidentEscalation,
    ) {}

    /**
     * Suelta el incidente. Sólo lo consigue quien lo tenía tomado.
     */
    public function execute(Incident $incident, User $user): bool
    {
        $rearmDueAt = null;

        $released = DB::transaction(function () use ($incident, $user, &$rearmDueAt) {
            $locked = Incident::query()
                ->whereKey($incident->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            if ($locked->team_id !== $user->current_team_id) {
                return false;
            }

            if ($locked->claimed_by_user_id !== $user->id) {
                return false;
            }

            $locked->forceFill([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
            ])->save();

            $this->appendTimelineEntry->execute(
                incident: $locked,
                entryType: TimelineEntryType::Released,
                actorType: TimelineActorType::User,
                actorId: $user->id,
                title: "Incidente soltado por {$user->name}",
            );

            // A claim-por-error released without ever being acknowledged must
            // not leave the incident unwatched forever: re-arm the SLA
            // watchdog at level 0, same as at incident creation.
            if ($locked->acknowledged_at === null && ! $locked->isTerminal() && $locked->sla_due_at !== null) {
                $rearmDueAt = $locked->sla_due_at;
            }

            return true;
        });

        // Re-armed after the release committed, with a new generation: the
        // chain starts over at level 0 and its notices go out again (with the
        // same keys they would have been deduped against the first chain).
        if ($released && $rearmDueAt instanceof CarbonInterface) {
            $this->armIncidentEscalation->arm($incident, $rearmDueAt->isFuture() ? $rearmDueAt : now(), 'released');
        }

        // Igual que en ClaimIncident: sólo se anuncia una liberación real.
        if ($released) {
            broadcast(IncidentUpdatedBroadcast::fromModel($incident->freshOrFail()));
        }

        return $released;
    }
}
