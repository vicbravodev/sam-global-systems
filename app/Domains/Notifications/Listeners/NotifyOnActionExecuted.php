<?php

namespace App\Domains\Notifications\Listeners;

use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;

class NotifyOnActionExecuted
{
    private const NOTIFICATION_ACTION_TYPES = ['send_email', 'send_sms', 'send_push', 'send_whatsapp'];

    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    public function handle(ActionExecuted $event): void
    {
        $execution = $event->execution;
        $actionType = $execution->action_type?->value;

        if ($execution->team_id === null || ! in_array($actionType, self::NOTIFICATION_ACTION_TYPES, true)) {
            return;
        }

        $payload = (array) ($execution->payload_json ?? []);

        $this->sendNotification->execute(
            teamId: (int) $execution->team_id,
            notificationType: 'automation.'.$actionType,
            sourceType: NotificationSourceType::ActionExecution,
            sourceReferenceId: (string) $execution->id,
            priority: NotificationPriority::Normal,
            triggeredByType: NotificationTriggeredByType::Automation,
            triggeredById: $execution->id,
            eventKey: 'action_execution:'.$execution->id,
            payload: $payload,
            subject: $payload['subject'] ?? null,
            bodyPreview: $payload['body_preview'] ?? null,
        );
    }
}
