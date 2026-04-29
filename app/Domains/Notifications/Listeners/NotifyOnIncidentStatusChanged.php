<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Incidents\Events\IncidentClosed;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;

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

        $newStatus = $event instanceof IncidentStatusChanged ? $event->newStatus : 'closed';

        $this->sendNotification->execute(
            teamId: (int) $incident->team_id,
            notificationType: "incident.{$newStatus}",
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::Normal,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: "incident_status:{$incident->id}:{$newStatus}",
            payload: [
                'incident_id' => $incident->id,
                'new_status' => $newStatus,
            ],
            subject: 'Incident status updated',
            bodyPreview: "Incident #{$incident->id} is now {$newStatus}.",
        );
    }
}
