<?php

namespace App\Domains\Automation\Actions;

use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Actions\RequestIncidentReview;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExecuteAction
{
    public function __construct(
        private ResolveActionTemplate $resolveActionTemplate,
        private readonly SendNotification $sendNotificationAction,
        private readonly AssignIncident $assignIncidentAction,
        private readonly EscalateIncident $escalateIncidentAction,
        private readonly RequestIncidentReview $requestIncidentReviewAction,
        private readonly RecordUsageEvent $recordUsageEvent,
    ) {}

    /**
     * Run the action attached to the given execution record. Updates state in place
     * and dispatches the appropriate domain event.
     */
    public function execute(ActionExecution $execution): ActionExecution
    {
        $execution->status = ActionExecutionStatus::Running;
        $execution->attempts = $execution->attempts + 1;
        $execution->save();

        try {
            $response = $this->dispatchByType($execution);

            $execution->status = ActionExecutionStatus::Completed;
            $execution->response_json = $response;
            $execution->error_message = null;
            $execution->executed_at = now();
            $execution->save();

            ActionExecutionLog::create([
                'action_execution_id' => $execution->id,
                'log_type' => ActionLogType::Info,
                'message' => 'Action completed.',
                'payload_json' => ['response' => $response],
            ]);

            $this->recordActionUsage($execution);

            ActionExecuted::dispatch($execution);

            return $execution;
        } catch (Throwable $exception) {
            $execution->status = ActionExecutionStatus::Failed;
            $execution->error_message = $exception->getMessage();
            $execution->save();

            ActionExecutionLog::create([
                'action_execution_id' => $execution->id,
                'log_type' => ActionLogType::Error,
                'message' => $exception->getMessage(),
                'payload_json' => null,
            ]);

            ActionFailed::dispatch($execution, $exception->getMessage());

            return $execution;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchByType(ActionExecution $execution): array
    {
        return match ($execution->action_type) {
            ActionType::CallWebhook => $this->callWebhook($execution),
            ActionType::SendEmail,
            ActionType::SendWhatsapp,
            ActionType::SendSms,
            ActionType::SendPush => $this->sendNotification($execution),
            ActionType::AssignIncident => $this->assignIncident($execution),
            ActionType::Escalate => $this->escalateIncident($execution),
            ActionType::RequestHumanReview => $this->requestHumanReview($execution),
            // CreateTicket needs an external ticketing integration and
            // UpdateAssetState a write path into Assets — both deliberately
            // deferred to V2 until a real use case lands (Roadmap B7/§5).
            ActionType::CreateTicket,
            ActionType::UpdateAssetState => [
                'stub' => true,
                'reason' => 'deferred_v2',
                'action_type' => $execution->action_type->value,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function callWebhook(ActionExecution $execution): array
    {
        $template = $execution->action_template_id !== null
            ? $execution->actionTemplate()->first()
            : null;

        $config = $template?->config_json ?? [];
        $url = (string) ($config['url'] ?? $execution->target_reference ?? '');

        if ($url === '') {
            throw new \RuntimeException('Webhook action requires a target URL.');
        }

        $timeoutSeconds = (int) ($config['timeout_seconds'] ?? 30);
        $payload = $execution->payload_json ?? [];

        $response = Http::timeout($timeoutSeconds)->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException("Webhook returned status {$response->status()}");
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];
    }

    /**
     * Bridge Send* actions into the Notifications pipeline (Roadmap B7): the
     * recipients come from the step target, the channel preference pins the
     * action's channel, and delivery/usage records stay owned by Notifications.
     *
     * @return array<string, mixed>
     */
    private function sendNotification(ActionExecution $execution): array
    {
        $channelType = match ($execution->action_type) {
            ActionType::SendEmail => ChannelType::Email,
            ActionType::SendSms => ChannelType::Sms,
            ActionType::SendWhatsapp => ChannelType::Whatsapp,
            ActionType::SendPush => ChannelType::Push,
            default => throw new \RuntimeException('Unsupported notification action type.'),
        };

        $recipients = $this->resolveNotificationRecipients($execution, $channelType);

        if ($recipients === []) {
            throw new \RuntimeException(
                "Could not resolve recipients for {$execution->action_type->value} "
                ."(target_type={$execution->target_type}, target_reference={$execution->target_reference})."
            );
        }

        $template = $execution->action_template_id !== null
            ? $execution->actionTemplate()->first()
            : null;

        $variables = (array) ($execution->payload_json ?? []);

        $subject = $template?->subject_template !== null && $template?->subject_template !== ''
            ? $this->renderTemplate($template->subject_template, $variables)
            : (string) ($variables['subject'] ?? 'Automated action notification');

        $body = $template?->body_template !== null && $template?->body_template !== ''
            ? $this->renderTemplate($template->body_template, $variables)
            : (string) ($variables['body'] ?? $variables['message'] ?? $subject);

        $priority = NotificationPriority::tryFrom((string) ($variables['priority'] ?? '')) ?? NotificationPriority::Normal;

        $notification = $this->sendNotificationAction->execute(
            teamId: $execution->team_id,
            notificationType: 'automation.'.$execution->action_type->value,
            sourceType: NotificationSourceType::ActionExecution,
            sourceReferenceId: (string) $execution->id,
            priority: $priority,
            triggeredByType: NotificationTriggeredByType::Automation,
            triggeredById: null,
            eventKey: 'automation_action:'.$execution->id,
            payload: array_merge($variables, [
                'recipients' => $recipients,
                'force_channels' => [$channelType->value],
            ]),
            subject: $subject,
            bodyPreview: $body,
        );

        // The dispatch job runs inline on the sync queue; if delivery already
        // failed outright, surface it as an action failure for retry/alerting.
        if ($notification->refresh()->status === NotificationStatus::Failed) {
            throw new \RuntimeException("Notification {$notification->id} failed to deliver on every channel.");
        }

        return [
            'notification_id' => $notification->id,
            'channel' => $channelType->value,
            'recipients' => count($recipients),
            'notification_status' => $notification->status->value,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveNotificationRecipients(ActionExecution $execution, ChannelType $channelType): array
    {
        $payload = (array) ($execution->payload_json ?? []);

        $explicit = $payload['recipients'] ?? null;

        if (is_array($explicit) && $explicit !== []) {
            return array_values(array_map(
                fn (array $recipient): array => $recipient + ['channel_preference' => $channelType->value],
                array_filter($explicit, 'is_array'),
            ));
        }

        $target = trim((string) ($execution->target_reference ?? ''));

        if ($target === '') {
            return [];
        }

        return match ($execution->target_type) {
            'user' => $this->userRecipients([(int) $target], $channelType),
            'role' => $this->roleRecipients($execution->team_id, $target, $channelType),
            // 'email', 'phone', 'address' and anything else carrying a raw
            // address routes the literal target through the channel.
            default => [[
                'address' => $target,
                'recipient_type' => 'external_contact',
                'channel_preference' => $channelType->value,
            ]],
        };
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    private function userRecipients(array $userIds, ChannelType $channelType): array
    {
        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user): array => [
                // The push driver resolves device tokens by user id; every
                // other channel addresses the user by email.
                'address' => $channelType === ChannelType::Push ? (string) $user->id : (string) $user->email,
                'name' => $user->name,
                'recipient_type' => 'user',
                'recipient_reference_id' => (string) $user->id,
                'channel_preference' => $channelType->value,
            ])
            ->filter(fn (array $recipient): bool => $recipient['address'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function roleRecipients(int $teamId, string $role, ChannelType $channelType): array
    {
        $userIds = Membership::query()
            ->where('team_id', $teamId)
            ->where('role', $role)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->userRecipients($userIds, $channelType);
    }

    /**
     * @return array<string, mixed>
     */
    private function assignIncident(ActionExecution $execution): array
    {
        $incident = $this->resolveIncident($execution);
        $payload = (array) ($execution->payload_json ?? []);

        $assigneeId = (int) ($execution->target_reference ?? $payload['assignee_id'] ?? 0);

        if ($assigneeId <= 0) {
            throw new \RuntimeException('Assign incident action requires an assignee id in target_reference.');
        }

        $assigneeType = AssigneeType::tryFrom((string) ($payload['assignee_type'] ?? '')) ?? AssigneeType::User;

        $assignment = $this->assignIncidentAction->execute(
            incident: $incident,
            assigneeType: $assigneeType,
            assigneeId: $assigneeId,
            role: isset($payload['role']) ? (string) $payload['role'] : null,
            assignedByType: IncidentCreatorType::System,
        );

        return [
            'incident_id' => $incident->id,
            'assignment_id' => $assignment->id,
            'assigned_to_type' => $assigneeType->value,
            'assigned_to_id' => $assigneeId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function escalateIncident(ActionExecution $execution): array
    {
        $incident = $this->resolveIncident($execution);
        $payload = (array) ($execution->payload_json ?? []);

        $fresh = $this->escalateIncidentAction->execute(
            incident: $incident,
            reason: (string) ($payload['reason'] ?? 'Escalated by automation workflow.'),
            escalatedByType: IncidentCreatorType::System,
        );

        return [
            'incident_id' => $fresh->id,
            'status' => $fresh->status?->code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestHumanReview(ActionExecution $execution): array
    {
        $incident = $this->resolveIncident($execution);
        $payload = (array) ($execution->payload_json ?? []);

        $fresh = $this->requestIncidentReviewAction->execute(
            incident: $incident,
            reason: (string) ($payload['reason'] ?? 'Human review requested by automation workflow.'),
            requestedByType: IncidentCreatorType::System,
        );

        return [
            'incident_id' => $fresh->id,
            'status' => $fresh->status?->code,
        ];
    }

    private function resolveIncident(ActionExecution $execution): Incident
    {
        $incidentId = (int) ($execution->incident_id
            ?? ($execution->source_type?->value === 'incident' ? $execution->source_reference_id : 0));

        if ($incidentId <= 0) {
            throw new \RuntimeException("{$execution->action_type->value} action requires a linked incident.");
        }

        $incident = Incident::withoutGlobalScopes()
            ->whereKey($incidentId)
            ->where('team_id', $execution->team_id)
            ->first();

        if ($incident === null) {
            throw new \RuntimeException("Incident {$incidentId} not found for this team.");
        }

        return $incident;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function renderTemplate(string $template, array $variables): string
    {
        try {
            return (string) Blade::render($template, $variables);
        } catch (Throwable) {
            return $template;
        }
    }

    private function recordActionUsage(ActionExecution $execution): void
    {
        if (! UsageMeter::query()->where('code', 'automation_actions')->exists()) {
            return;
        }

        $this->recordUsageEvent->execute(
            teamId: $execution->team_id,
            meterCode: 'automation_actions',
            quantity: 1,
            eventKey: 'automation_action:'.$execution->id,
            metadata: [
                'action_execution_id' => $execution->id,
                'action_type' => $execution->action_type->value,
            ],
        );
    }
}
