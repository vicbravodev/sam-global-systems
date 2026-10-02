<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TeamMembers;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Aviso de cambio de estado de un incidente, dirigido SÓLO a quien lo lleva
 * (asignado y/o quien lo tomó), nunca al equipo entero ni a quien hizo el
 * cambio.
 *
 * - `in_review` es un estado interno del pipeline (la automatización pide
 *   revisión humana justo tras crear): no se notifica.
 * - `escalated` por el sistema (SLA, verificación) ya lo notifica la escalera:
 *   aquí no se duplica.
 * - Canal: siempre web + correo, también en un crítico: es informativo.
 * - Sólo escucha IncidentStatusChanged: IncidentClosed llega siempre detrás
 *   del mismo cambio y sin actor, así que avisaba a quien cerró.
 */
class NotifyOnIncidentStatusChanged
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(IncidentStatusChanged $event): void
    {
        $incident = $event->incident;

        $newStatus = $event->newStatus;
        $actorUserId = $event->actorUserId;

        // Corre dentro de EscalateIncident/CloseIncident: las líneas salen
        // tras el commit más externo.
        $logInput = ['incident_id' => $incident->id, 'new_status' => $newStatus];

        if ($newStatus === IncidentStatusCode::InReview->value) {
            DB::afterCommit(fn () => SystemLog::skipped('notifications.status_change.skipped', reason: 'internal_status', input: $logInput, debug: true));

            return;
        }

        TenantContext::for($incident->team_id, function () use ($incident, $newStatus, $actorUserId, $logInput): void {
            // Una escalación del sistema (SLA, verificación sin respuesta,
            // emergencia confirmada) ya avisa por la escalera, a quien toca:
            // aquí sería el mismo aviso otra vez.
            if ($newStatus === IncidentStatusCode::Escalated->value && $actorUserId === null) {
                $reason = $this->escalatedBySla($incident) ? 'escalated_by_sla' : 'escalated_by_system';
                DB::afterCommit(fn () => SystemLog::skipped('notifications.status_change.skipped', reason: $reason, input: $logInput));

                return;
            }

            ['recipients' => $recipients, 'calc' => $recipientCalc] = $this->recipients($incident, $actorUserId);

            if ($recipients === []) {
                DB::afterCommit(fn () => SystemLog::skipped('notifications.status_change.skipped', reason: 'no_recipients', input: $logInput, calc: $recipientCalc));

                return;
            }

            $priority = NotificationPriority::fromIncidentPriority($incident->priority?->code);

            // Quien lleva el incidente ya está encima: un cambio de estado es
            // informativo, nunca un SMS o una llamada (decisión 2026-10-01).
            $payload = [
                'incident_id' => $incident->id,
                'new_status' => $newStatus,
                'recipients' => $recipients,
                'force_channels' => [ChannelType::Web->value, ChannelType::Email->value],
            ];

            $this->sendNotification->execute(
                teamId: $incident->team_id,
                notificationType: "incident.{$newStatus}",
                sourceType: NotificationSourceType::Incident,
                sourceReferenceId: (string) $incident->id,
                priority: $priority,
                triggeredByType: $actorUserId !== null ? NotificationTriggeredByType::User : NotificationTriggeredByType::System,
                triggeredById: $actorUserId,
                // Una transición por clave: volver al mismo estado (reabrir y
                // cerrar otra vez, escalar de nuevo) también avisa.
                eventKey: "incident_status:{$incident->id}:{$newStatus}:".($incident->updated_at?->getTimestamp() ?? 0),
                payload: $payload,
                subject: 'Estado del incidente actualizado',
                bodyPreview: "El incidente {$incident->reference()} pasó a ".IncidentStatusPresenter::label($newStatus).'.',
            );
        });
    }

    private function escalatedBySla(Incident $incident): bool
    {
        return IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::SlaBreached->value)
            ->exists();
    }

    /**
     * Asignado actual (usuario) + quien reclamó el incidente, miembros del
     * team, sin el actor del cambio. `calc` sólo lleva conteos: nunca ids de
     * candidatos, que pueden ser de otro tenant.
     *
     * @return array{recipients: array<int, array<string, mixed>>, calc: array{candidates_count: int, actor_excluded: bool, non_member_dropped_count: int, without_email_dropped_count: int}}
     */
    private function recipients(Incident $incident, ?int $actorUserId): array
    {
        $userIds = [];

        $assignment = $incident->currentAssignment()->first();

        if ($assignment !== null && $assignment->assigned_to_type === AssigneeType::User) {
            $userIds[] = $assignment->assigned_to_id;
        }

        if ($incident->claimed_by_user_id !== null) {
            $userIds[] = $incident->claimed_by_user_id;
        }

        $candidates = array_values(array_unique(array_filter($userIds, fn (int $id): bool => $id > 0)));

        $userIds = array_values(array_unique(array_filter(
            $userIds,
            fn (int $id): bool => $id > 0 && $id !== $actorUserId,
        )));

        $calc = [
            'candidates_count' => count($candidates),
            'actor_excluded' => $actorUserId !== null && in_array($actorUserId, $candidates, true),
            'non_member_dropped_count' => 0,
            'without_email_dropped_count' => 0,
        ];

        if ($userIds === []) {
            return ['recipients' => [], 'calc' => $calc];
        }

        $members = TeamMembers::scope(User::query()->whereIn('id', $userIds), $incident->team_id)->get();
        $withEmail = $members->filter(fn (User $user): bool => $user->email !== '');

        $calc['non_member_dropped_count'] = count($userIds) - $members->count();
        $calc['without_email_dropped_count'] = $members->count() - $withEmail->count();

        $recipients = $withEmail
            ->map(fn (User $user): array => [
                'recipient_type' => 'user',
                'address' => $user->email,
                'email' => $user->email,
                'phone' => $user->verifiedPhone(),
                'name' => $user->name,
                'recipient_reference_id' => (string) $user->id,
            ])
            ->values()
            ->all();

        return ['recipients' => $recipients, 'calc' => $calc];
    }
}
