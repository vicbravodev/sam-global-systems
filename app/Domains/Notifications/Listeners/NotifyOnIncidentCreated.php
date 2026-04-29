<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;

/**
 * SPEC-11-DEFERRED: bridges Incidents -> Notifications. Listens by FQCN string for
 * `App\Domains\Incidents\Events\IncidentCreated` so this domain compiles before spec
 * 11 lands. The listener only reads documented public properties via duck-typing.
 */
class NotifyOnIncidentCreated
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(object $event): void
    {
        $teamId = $this->intProperty($event, 'teamId') ?? $this->intProperty($event, 'team_id');
        $incidentId = $this->intProperty($event, 'incidentId') ?? $this->intProperty($event, 'incident_id');

        if ($teamId === null) {
            return;
        }

        $priority = $this->resolvePriority($event);

        $payload = [
            'incident_id' => $incidentId,
            'incident_type' => $this->stringProperty($event, 'incidentType')
                ?? $this->stringProperty($event, 'incident_type'),
            'severity' => $this->stringProperty($event, 'severity'),
        ];

        $this->sendNotification->execute(
            teamId: $teamId,
            notificationType: 'incident.created',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: $incidentId !== null ? (string) $incidentId : null,
            priority: $priority,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_created:'.($incidentId ?? uniqid('no-id', true)),
            payload: $payload,
            subject: 'New incident created',
            bodyPreview: 'A new incident has been reported on your team.',
        );
    }

    private function resolvePriority(object $event): NotificationPriority
    {
        $severity = $this->stringProperty($event, 'severity');

        return match ($severity) {
            'critical' => NotificationPriority::Critical,
            'high' => NotificationPriority::High,
            'low' => NotificationPriority::Low,
            default => NotificationPriority::Normal,
        };
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
