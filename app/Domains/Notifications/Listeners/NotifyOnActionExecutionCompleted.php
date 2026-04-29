<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;

/**
 * SPEC-12-DEFERRED: bridges Automation -> Notifications. Listens by FQCN string for
 * `App\Domains\Automation\Events\ActionExecutionCompleted`. Triggered when an
 * automation action of type `send_email`, `send_sms`, `send_push`, `send_whatsapp`
 * completes; this listener forwards it through SendNotification for tracking and
 * delivery on the right channels.
 */
class NotifyOnActionExecutionCompleted
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(object $event): void
    {
        $teamId = $this->intProperty($event, 'teamId') ?? $this->intProperty($event, 'team_id');
        $executionId = $this->intProperty($event, 'executionId') ?? $this->intProperty($event, 'execution_id');
        $actionType = $this->stringProperty($event, 'actionType') ?? $this->stringProperty($event, 'action_type');

        if ($teamId === null || ! $this->isNotificationAction($actionType)) {
            return;
        }

        $payload = $this->arrayProperty($event, 'payload') ?? [];

        $this->sendNotification->execute(
            teamId: $teamId,
            notificationType: 'automation.'.($actionType ?? 'action'),
            sourceType: NotificationSourceType::ActionExecution,
            sourceReferenceId: $executionId !== null ? (string) $executionId : null,
            priority: NotificationPriority::Normal,
            triggeredByType: NotificationTriggeredByType::Automation,
            triggeredById: $executionId,
            eventKey: 'action_execution:'.($executionId ?? uniqid('no-id', true)),
            payload: $payload,
            subject: $payload['subject'] ?? null,
            bodyPreview: $payload['body_preview'] ?? null,
        );
    }

    private function isNotificationAction(?string $actionType): bool
    {
        return in_array($actionType, ['send_email', 'send_sms', 'send_push', 'send_whatsapp'], true);
    }

    private function intProperty(object $event, string $key): ?int
    {
        return isset($event->{$key}) && is_numeric($event->{$key}) ? (int) $event->{$key} : null;
    }

    private function stringProperty(object $event, string $key): ?string
    {
        return isset($event->{$key}) && is_string($event->{$key}) ? $event->{$key} : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayProperty(object $event, string $key): ?array
    {
        return isset($event->{$key}) && is_array($event->{$key}) ? $event->{$key} : null;
    }
}
