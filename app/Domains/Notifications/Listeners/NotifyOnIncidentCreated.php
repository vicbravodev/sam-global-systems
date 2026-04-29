<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;

class NotifyOnIncidentCreated
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(IncidentCreated $event): void
    {
        $incident = $event->incident;

        if ($incident->team_id === null) {
            return;
        }

        $severity = $incident->priority?->code;

        $this->sendNotification->execute(
            teamId: (int) $incident->team_id,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: $this->mapPriority($severity),
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:'.$incident->id,
            payload: [
                'incident_id' => $incident->id,
                'incident_type' => $incident->type?->code,
                'severity' => $severity,
            ],
            subject: 'New incident created',
            bodyPreview: 'A new incident has been reported on your team.',
        );
    }

    private function mapPriority(?string $severity): NotificationPriority
    {
        return match ($severity) {
            'critical' => NotificationPriority::Critical,
            'high' => NotificationPriority::High,
            'low' => NotificationPriority::Low,
            default => NotificationPriority::Normal,
        };
    }
}
