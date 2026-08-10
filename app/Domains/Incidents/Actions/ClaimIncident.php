<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimIncident
{
    public function __construct(private readonly AppendTimelineEntry $appendTimelineEntry) {}

    /**
     * Toma el incidente para este usuario. Devuelve false si ya lo tenía otro.
     * El bloqueo pesimista garantiza que en una carrera gane exactamente uno.
     */
    public function execute(Incident $incident, User $user): bool
    {
        $claimed = DB::transaction(function () use ($incident, $user) {
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

            if ($locked->claimed_by_user_id !== null && $locked->claimed_by_user_id !== $user->id) {
                return false;
            }

            $locked->forceFill([
                'claimed_by_user_id' => $user->id,
                'claimed_at' => now(),
            ])->save();

            $this->appendTimelineEntry->execute(
                incident: $locked,
                entryType: TimelineEntryType::Claimed,
                actorType: TimelineActorType::User,
                actorId: $user->id,
                title: "Incidente tomado por {$user->name}",
            );

            return true;
        });

        // Sólo se anuncia una toma real: perder la carrera no debe avisar a
        // nadie. Fuera de la transacción para no emitir un evento de una
        // escritura que todavía podría revertirse.
        if ($claimed) {
            broadcast(IncidentUpdatedBroadcast::fromModel($incident->fresh()));
        }

        return $claimed;
    }
}
