<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentClosed;
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
 * - `escalated` por SLA ya lo notifica CheckIncidentAcknowledgementJob con su
 *   cadena de escalación: aquí no se duplica.
 * - Canal: web + correo; sólo un incidente crítico usa la política crítica
 *   (SMS/push) del tenant.
 */
class NotifyOnIncidentStatusChanged
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(IncidentStatusChanged|IncidentClosed $event): void
    {
        $incident = $event->incident;

        if ($incident->team_id === null) {
            return;
        }

        $newStatus = $event instanceof IncidentStatusChanged ? $event->newStatus : IncidentStatusCode::Closed->value;
        $actorUserId = $event instanceof IncidentStatusChanged ? $event->actorUserId : null;

        // Corre dentro de EscalateIncident/CloseIncident: las líneas salen
        // tras el commit más externo.
        $logInput = ['incident_id' => $incident->id, 'new_status' => $newStatus];

        if ($newStatus === IncidentStatusCode::InReview->value) {
            DB::afterCommit(fn () => SystemLog::skipped('notifications.status_change.skipped', reason: 'internal_status', input: $logInput, debug: true));

            return;
        }

        TenantContext::for((int) $incident->team_id, function () use ($incident, $newStatus, $actorUserId, $logInput): void {
            if ($newStatus === IncidentStatusCode::Escalated->value && $this->escalatedBySla($incident)) {
                DB::afterCommit(fn () => SystemLog::skipped('notifications.status_change.skipped', reason: 'escalated_by_sla', input: $logInput));

                return;
            }

            ['recipients' => $recipients, 'calc' => $recipientCalc] = $this->recipients($incident, $actorUserId);

            if ($recipients === []) {
                DB::afterCommit(fn () => SystemLog::skipped('notifications.status_change.skipped', reason: 'no_recipients', input: $logInput, calc: $recipientCalc));

                return;
            }

            $priority = NotificationPriority::fromIncidentPriority($incident->priority?->code);

            $payload = [
                'incident_id' => $incident->id,
                'new_status' => $newStatus,
                'recipients' => $recipients,
            ];

            if (! $priority->isCritical()) {
                $payload['force_channels'] = [ChannelType::Web->value, ChannelType::Email->value];
            }

            $this->sendNotification->execute(
                teamId: (int) $incident->team_id,
                notificationType: "incident.{$newStatus}",
                sourceType: NotificationSourceType::Incident,
                sourceReferenceId: (string) $incident->id,
                priority: $priority,
                triggeredByType: $actorUserId !== null ? NotificationTriggeredByType::User : NotificationTriggeredByType::System,
                triggeredById: $actorUserId,
                eventKey: "incident_status:{$incident->id}:{$newStatus}",
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
            $userIds[] = (int) $assignment->assigned_to_id;
        }

        if ($incident->claimed_by_user_id !== null) {
            $userIds[] = (int) $incident->claimed_by_user_id;
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

        $members = TeamMembers::scope(User::query()->whereIn('id', $userIds), (int) $incident->team_id)->get();
        $withEmail = $members->filter(fn (User $user): bool => (string) $user->email !== '');

        $calc['non_member_dropped_count'] = count($userIds) - $members->count();
        $calc['without_email_dropped_count'] = $members->count() - $withEmail->count();

        $recipients = $withEmail
            ->map(fn (User $user): array => [
                'recipient_type' => 'user',
                'address' => (string) $user->email,
                'email' => (string) $user->email,
                'phone' => $user->verifiedPhone(),
                'name' => $user->name,
                'recipient_reference_id' => (string) $user->id,
            ])
            ->values()
            ->all();

        return ['recipients' => $recipients, 'calc' => $calc];
    }
}
