<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;

/**
 * SPEC-11-DEFERRED: bridges Incidents -> Notifications. Listens by FQCN string for
 * both `App\Domains\Incidents\Events\IncidentStatusChanged` and `IncidentClosed`.
 */
class NotifyOnIncidentStatusChanged
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(object $event): void
    {
        $teamId = $this->intProperty($event, 'teamId') ?? $this->intProperty($event, 'team_id');
        $incidentId = $this->intProperty($event, 'incidentId') ?? $this->intProperty($event, 'incident_id');

        if ($teamId === null || $incidentId === null) {
            return;
        }

        $newStatus = $this->stringProperty($event, 'newStatus')
            ?? $this->stringProperty($event, 'new_status')
            ?? $this->stringProperty($event, 'status')
            ?? 'updated';

        $this->sendNotification->execute(
            teamId: $teamId,
            notificationType: "incident.{$newStatus}",
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incidentId,
            priority: NotificationPriority::Normal,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: "incident_status:{$incidentId}:{$newStatus}",
            payload: [
                'incident_id' => $incidentId,
                'new_status' => $newStatus,
            ],
            subject: 'Incident status updated',
            bodyPreview: "Incident #{$incidentId} is now {$newStatus}.",
        );
    }

    private function intProperty(object $event, string $key): ?int
    {
        return isset($event->{$key}) && is_numeric($event->{$key}) ? (int) $event->{$key} : null;
    }

    private function stringProperty(object $event, string $key): ?string
    {
        return isset($event->{$key}) && is_string($event->{$key}) ? $event->{$key} : null;
    }
}
