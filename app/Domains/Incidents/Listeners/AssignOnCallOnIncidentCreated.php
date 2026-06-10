<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Actions\ResolveOnCallOperator;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Models\User;

/**
 * Auto-assign new incidents to the tenant's on-call operator (Roadmap B6-P5).
 *
 * The on-call comes from the active TenantScheduleProfile's shift rules; when
 * none is configured the incident stays unassigned, exactly as before. A
 * critical incident additionally sends a directed Critical notification to
 * the assignee so it reaches them on every channel they have configured.
 */
class AssignOnCallOnIncidentCreated
{
    public function __construct(
        private readonly ResolveOnCallOperator $resolveOnCallOperator,
        private readonly AssignIncident $assignIncident,
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident;

        if ($incident->team_id === null) {
            return;
        }

        if ($incident->currentAssignment()->exists()) {
            return;
        }

        $userId = $this->resolveOnCallOperator->execute((int) $incident->team_id, $incident->opened_at);

        if ($userId === null) {
            return;
        }

        $this->assignIncident->execute(
            incident: $incident,
            assigneeType: AssigneeType::User,
            assigneeId: $userId,
            role: 'on_call',
        );

        if ($incident->priority?->code === 'critical') {
            $this->notifyAssignee($incident, $userId);
        }
    }

    private function notifyAssignee(Incident $incident, int $userId): void
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->email) {
            return;
        }

        $this->sendNotification->execute(
            teamId: (int) $incident->team_id,
            notificationType: 'incident.assigned.on_call',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_oncall_assigned:'.$incident->id,
            payload: [
                'incident_id' => $incident->id,
                'incident_type' => $incident->type?->code,
                'severity' => $incident->priority?->code,
                'incident_title' => $incident->title,
                'recipients' => [
                    [
                        'recipient_type' => 'user',
                        'address' => $user->email,
                        'name' => $user->name,
                        'recipient_reference_id' => (string) $user->id,
                    ],
                ],
            ],
            subject: 'Incidente crítico asignado a ti (on-call)',
            bodyPreview: 'Se te asignó un incidente crítico como operador on-call.',
        );
    }
}
