<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Events\IncidentAssigned;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Support\IncidentNoticeCopy;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TeamMembers;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Avisa a quien le asignaron un incidente (en la app y en su dispositivo).
 * Nunca a quien asignó, ni la asignación de guardia (ya avisa
 * AssignOnCallOnIncidentCreated). Corre tras el commit: AssignIncident
 * despacha el evento dentro de su transacción.
 */
class NotifyOnIncidentAssigned implements ShouldQueue
{
    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(IncidentAssigned $event): void
    {
        $incident = $event->incident;
        $assignment = $event->assignment;

        TenantContext::for($incident->team_id, function () use ($incident, $assignment): void {
            $input = ['incident_id' => $incident->id, 'assignment_id' => $assignment->id];

            $reason = match (true) {
                $assignment->assigned_to_type !== AssigneeType::User => 'not_user',
                $assignment->role === 'on_call' => 'on_call_already_notified',
                $assignment->assigned_by_id !== null && $assignment->assigned_by_id === $assignment->assigned_to_id => 'self_assigned',
                $incident->isTerminal() => 'terminal',
                $this->isStale($assignment) => 'stale',
                default => null,
            };

            if ($reason !== null) {
                SystemLog::skipped('incidents.assignment.notified', reason: $reason, input: $input);

                return;
            }

            $user = TeamMembers::isMember($incident->team_id, $assignment->assigned_to_id)
                ? User::query()->find($assignment->assigned_to_id)
                : null;

            if ($user === null) {
                SystemLog::skipped('incidents.assignment.notified', reason: 'user_not_found', input: $input);

                return;
            }

            $copy = IncidentNoticeCopy::assigned($incident);
            $priority = $incident->priority?->code === 'critical' ? NotificationPriority::Critical : NotificationPriority::High;

            $notification = $this->sendNotification->execute(
                teamId: $incident->team_id,
                notificationType: 'incident.assigned',
                sourceType: NotificationSourceType::Incident,
                sourceReferenceId: (string) $incident->id,
                priority: $priority,
                triggeredByType: $assignment->assigned_by_id !== null ? NotificationTriggeredByType::User : NotificationTriggeredByType::System,
                triggeredById: $assignment->assigned_by_id,
                eventKey: 'incident_assigned:'.$assignment->id,
                payload: [
                    'incident_id' => $incident->id,
                    'incident_reference' => $incident->reference(),
                    'incident_type' => $incident->type?->code,
                    'severity' => $incident->priority?->code,
                    'incident_title' => $incident->title,
                    'force_channels' => [ChannelType::Web->value, ChannelType::Push->value],
                    'recipients' => [[
                        'recipient_type' => 'user',
                        'address' => $user->email,
                        'name' => $user->name,
                        'recipient_reference_id' => (string) $user->id,
                    ]],
                ],
                subject: $copy['subject'],
                bodyPreview: $copy['body'],
            );

            SystemLog::ok('incidents.assignment.notified', input: $input, result: [
                'notification_id' => $notification->id,
                'priority' => $priority->value,
            ]);
        });
    }

    private function isStale(IncidentAssignment $assignment): bool
    {
        return IncidentAssignment::query()
            ->whereKey($assignment->id)
            ->whereNotNull('unassigned_at')
            ->exists();
    }
}
