<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Actions\ResolveOnCallOperator;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\Notification;
use App\Models\User;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * Auto-assign new incidents to the tenant's on-call operator (Roadmap B6-P5).
 *
 * The on-call comes from the active TenantScheduleProfile's shift rules; when
 * none is configured the incident stays unassigned, exactly as before. A
 * critical incident additionally tells the assignee, in-app only, that it is
 * theirs: the out-of-band critical alert (SMS/push) already reaches them
 * through the team-wide `incident.created` notification, and a second SMS for
 * the same incident only costs money and trains people to ignore alerts.
 *
 * Runs inside CreateIncidentFromEvent's transaction: every log line goes
 * through DB::afterCommit, so a rolled-back creation never claims an
 * assignment.
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

        $input = ['incident_id' => $incident->id, 'stage' => 'on_call_listener'];

        if ($incident->currentAssignment()->exists()) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.assignment.resolved', reason: 'already_assigned', input: $input));

            return;
        }

        $explain = $this->resolveOnCallOperator->explain((int) $incident->team_id, $incident->opened_at);
        $userId = $explain['user_id'];

        if ($userId === null) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.assignment.resolved',
                reason: $explain['reason'] ?? 'no_eligible_member',
                input: $input,
                calc: $explain['calc'],
            ));

            return;
        }

        $assignment = $this->assignIncident->execute(
            incident: $incident,
            assigneeType: AssigneeType::User,
            assigneeId: $userId,
            role: 'on_call',
        );

        $assignedLine = [
            'input' => $input,
            'calc' => [...$explain['calc'], 'source' => $explain['source']],
            'result' => [
                'assignee_type' => AssigneeType::User->value,
                'assignee_user_id' => $userId,
                'assignment_id' => $assignment->id,
                'role' => 'on_call',
            ],
        ];
        DB::afterCommit(fn () => SystemLog::ok('incidents.assignment.resolved', ...$assignedLine));

        $notifyInput = ['incident_id' => $incident->id, 'assignee_user_id' => $userId];

        if ($incident->priority?->code === 'critical') {
            $notification = $this->notifyAssignee($incident, $userId);

            if ($notification === null) {
                DB::afterCommit(fn () => SystemLog::skipped('incidents.on_call.notified', reason: 'user_without_email', input: $notifyInput));

                return;
            }

            $notificationId = $notification->id;
            DB::afterCommit(fn () => SystemLog::ok('incidents.on_call.notified',
                input: $notifyInput,
                result: ['notification_id' => $notificationId, 'forced_channel_types' => [ChannelType::Web->value]],
            ));

            return;
        }

        DB::afterCommit(fn () => SystemLog::skipped('incidents.on_call.notified', reason: 'not_critical', input: $notifyInput));
    }

    private function notifyAssignee(Incident $incident, int $userId): ?Notification
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->email) {
            return null;
        }

        return $this->sendNotification->execute(
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
                'incident_reference' => $incident->reference(),
                'incident_type' => $incident->type?->code,
                'severity' => $incident->priority?->code,
                'incident_title' => $incident->title,
                'force_channels' => [ChannelType::Web->value],
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
