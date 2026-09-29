<?php

namespace App\Domains\Automation\Actions;

use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use App\Domains\Automation\Support\ActionFailure;
use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Actions\RequestIncidentReview;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationStatus;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Models\Membership;
use App\Models\User;
use App\Support\Http\OutboundUrlGuard;
use App\Support\Http\UnsafeOutboundUrlException;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TeamMembers;
use App\Support\Templates\TemplateInterpolator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class ExecuteAction
{
    private const WEBHOOK_MAX_TIMEOUT_SECONDS = 10;

    private const WEBHOOK_MAX_BODY_BYTES = 2048;

    public function __construct(
        private ResolveActionTemplate $resolveActionTemplate,
        private readonly SendNotification $sendNotificationAction,
        private readonly AssignIncident $assignIncidentAction,
        private readonly EscalateIncident $escalateIncidentAction,
        private readonly RequestIncidentReview $requestIncidentReviewAction,
        private readonly RecordUsageEvent $recordUsageEvent,
        private readonly TemplateInterpolator $interpolator,
        private readonly OutboundUrlGuard $outboundUrlGuard,
    ) {}

    /**
     * Run the action attached to the given execution record. Updates state in place
     * and dispatches the appropriate domain event.
     */
    public function execute(ActionExecution $execution): ActionExecution
    {
        $blocked = TenantCanSend::blockedReason($execution->team_id);

        if ($blocked !== null) {
            $cancelled = $this->cancel($execution, "Tenant cannot send: {$blocked}.");

            SystemLog::skipped('automation.action.stopped', reason: 'tenant_blocked', input: $this->logInput($execution), calc: ['blocked_reason' => $blocked]);

            return $cancelled;
        }

        $execution->status = ActionExecutionStatus::Running;
        $execution->attempts = $execution->attempts + 1;
        $execution->save();

        $started = hrtime(true);

        try {
            $response = $this->dispatchByType($execution);
            $durationMs = SystemLog::elapsedMs($started);

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

            SystemLog::ok(
                'automation.action.completed',
                input: $this->logInput($execution),
                calc: ['attempt' => $execution->attempts],
                result: $this->loggableResponse($execution, $response),
                durationMs: $durationMs,
            );

            return $execution;
        } catch (Throwable $exception) {
            $durationMs = SystemLog::elapsedMs($started);

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

            // Nunca `error: $exception`: el mensaje interpola target_reference
            // (teléfono, email o URL) e ids ajenos; ya queda en error_message.
            [$reason, $failureContext] = $this->failureReason($exception);

            SystemLog::degraded(
                'automation.action.failed',
                reason: $reason,
                input: $this->logInput($execution),
                calc: ['attempt' => $execution->attempts],
                result: ['error_class' => class_basename($exception), ...$failureContext],
                durationMs: $durationMs,
            );

            return $execution;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function logInput(ActionExecution $execution): array
    {
        return [
            'action_execution_id' => $execution->id,
            'action_type' => $execution->action_type->value,
            'execution_mode' => $execution->execution_mode?->value,
            'source_type' => $execution->source_type?->value,
            'incident_id' => $execution->incident_id,
        ];
    }

    /**
     * Sólo ids, estados y conteos de la respuesta: nunca `body` (lo controla
     * un tercero), `channel` ni el resto.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function loggableResponse(ActionExecution $execution, array $response): array
    {
        $isWebhook = $execution->action_type === ActionType::CallWebhook;

        return array_filter([
            'notification_id' => $response['notification_id'] ?? null,
            'notification_status' => $response['notification_status'] ?? null,
            'recipients_count' => $response['recipients'] ?? null,
            'assignment_id' => $response['assignment_id'] ?? null,
            'status_code' => $isWebhook ? null : ($response['status'] ?? null),
            'http_status' => $isWebhook ? ($response['status'] ?? null) : null,
            'stub' => isset($response['stub']) ? (bool) $response['stub'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Código estable del fallo y su contexto seguro (nunca el mensaje).
     *
     * @return array{0: string, 1: array<string, int|string|bool|null>}
     */
    private function failureReason(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ActionFailure => [$exception->kind, $exception->context],
            // El host va detrás de ':' y no se registra.
            $exception instanceof UnsafeOutboundUrlException => ['unsafe_url', ['unsafe_url_code' => LoggableCode::guard(strtok($exception->reason, ':') ?: null)]],
            // Guard de AssignIncident: su mensaje lleva un id de usuario que puede ser ajeno.
            $exception instanceof InvalidArgumentException => ['invalid_assignee', []],
            default => ['unexpected_exception', []],
        };
    }

    /**
     * Cierra una ejecución que ya no debe correr, dejando el motivo en la
     * propia ejecución y en su log (nunca se descarta en silencio).
     */
    public function cancel(ActionExecution $execution, string $reason): ActionExecution
    {
        $execution->status = ActionExecutionStatus::Cancelled;
        $execution->error_message = $reason;
        $execution->save();

        ActionExecutionLog::create([
            'action_execution_id' => $execution->id,
            'log_type' => ActionLogType::Info,
            'message' => $reason,
            'payload_json' => null,
        ]);

        return $execution;
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
            throw new ActionFailure('webhook_url_missing', 'Webhook action requires a target URL.');
        }

        // URL controlada por el tenant: SSRF. El guard rechaza la red interna
        // (también vía DNS) y devuelve las IPs validadas para fijarlas en la
        // conexión; sin redirecciones, timeout acotado y respuesta truncada.
        $target = $this->outboundUrlGuard->assertSafe($url);

        $timeoutSeconds = min(self::WEBHOOK_MAX_TIMEOUT_SECONDS, max(1, (int) ($config['timeout_seconds'] ?? self::WEBHOOK_MAX_TIMEOUT_SECONDS)));
        $payload = $execution->payload_json ?? [];

        try {
            $response = Http::withOptions($target->httpOptions())
                ->connectTimeout(min(5, $timeoutSeconds))
                ->timeout($timeoutSeconds)
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            // El mensaje de cURL describe la red (IPs, puertos): se registra,
            // pero al tenant le llega uno genérico.
            SystemLog::degraded('automation.webhook.connection_failed', reason: 'connection_failed', input: ['action_execution_id' => $execution->id], error: $exception);

            throw new ActionFailure('webhook_connection_failed', 'No se pudo conectar con el webhook.');
        }

        if ($response->redirect()) {
            throw new ActionFailure('webhook_redirect', "Webhook returned a redirect ({$response->status()}); redirects are not followed.", ['http_status' => $response->status()]);
        }

        if ($response->failed()) {
            throw new ActionFailure('webhook_http_error', "Webhook returned status {$response->status()}", ['http_status' => $response->status()]);
        }

        return [
            'status' => $response->status(),
            'body' => $this->truncatedBody($response->body()),
        ];
    }

    /**
     * Sólo se guarda un extracto de la respuesta: el cuerpo lo controla un
     * tercero y termina en response_json / logs visibles en la UI.
     */
    private function truncatedBody(string $body): mixed
    {
        if (strlen($body) <= self::WEBHOOK_MAX_BODY_BYTES) {
            $decoded = json_decode($body, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
        }

        return mb_strcut($body, 0, self::WEBHOOK_MAX_BODY_BYTES).'…';
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
            default => throw new ActionFailure('unsupported_notification_action', 'Unsupported notification action type.'),
        };

        $recipients = $this->resolveNotificationRecipients($execution, $channelType);

        if ($recipients === []) {
            throw new ActionFailure(
                'no_recipients',
                "Could not resolve recipients for {$execution->action_type->value} "
                ."(target_type={$execution->target_type}, target_reference={$execution->target_reference})."
            );
        }

        $template = $execution->action_template_id !== null
            ? $execution->actionTemplate()->first()
            : null;

        $variables = (array) ($execution->payload_json ?? []);
        $variables['incident'] ??= $this->incidentVariables($execution);

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
            throw new ActionFailure('notification_not_delivered', "Notification {$notification->id} failed to deliver on every channel.", ['notification_id' => $notification->id]);
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
            'user' => $this->userRecipients($execution->team_id, [(int) $target], $channelType),
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
    private function userRecipients(int $teamId, array $userIds, ChannelType $channelType): array
    {
        // Los ids de usuario son globales: sólo se notifica a miembros del
        // team de la ejecución (como ResolveOnCallOperator::isMember). Un id
        // ajeno se descarta y queda en el log.
        $users = TeamMembers::scope(User::query()->whereIn('id', $userIds), $teamId)->get();

        $skipped = array_values(array_diff($userIds, $users->modelKeys()));

        if ($skipped !== []) {
            SystemLog::skipped('automation.recipients.non_member_skipped', reason: 'not_team_member', input: ['team_id' => $teamId, 'skipped_user_ids' => $skipped]);
        }

        return $users
            ->map(fn (User $user): array => [
                // The push driver resolves device tokens by user id; every
                // other channel addresses the user by email.
                'address' => $channelType === ChannelType::Push ? (string) $user->id : (string) $user->email,
                'email' => (string) $user->email,
                // SMS/WhatsApp salen al teléfono verificado; sin él la entrega
                // queda registrada como skipped (sin dirección), no se inventa.
                'phone' => $user->verifiedPhone(),
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

        return $this->userRecipients($teamId, $userIds, $channelType);
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
            throw new ActionFailure('assignee_missing', 'Assign incident action requires an assignee id in target_reference.');
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

    /**
     * `{{incident.*}}` variables for tenant templates. `code` is the
     * per-tenant reference (INC-00036) — the one format shown everywhere;
     * the global id is never exposed in outbound messages.
     *
     * @return array<string, string|null>|null
     */
    private function incidentVariables(ActionExecution $execution): ?array
    {
        $incidentId = (int) ($execution->incident_id
            ?? ($execution->source_type?->value === 'incident' ? $execution->source_reference_id : 0));

        if ($incidentId <= 0) {
            return null;
        }

        $incident = Incident::query()
            ->whereKey($incidentId)
            ->where('team_id', $execution->team_id)
            ->with(['priority', 'status', 'team'])
            ->first();

        if ($incident === null) {
            return null;
        }

        $slug = $incident->team?->slug;

        return [
            'code' => $incident->reference(),
            'title' => $incident->title,
            'summary' => $incident->summary,
            'priority' => $incident->priority?->name ?? $incident->priority?->code,
            'status' => IncidentStatusPresenter::label($incident->status?->code),
            'url' => $slug !== null ? url("/{$slug}/incidents/{$incident->id}") : null,
        ];
    }

    private function resolveIncident(ActionExecution $execution): Incident
    {
        $incidentId = (int) ($execution->incident_id
            ?? ($execution->source_type?->value === 'incident' ? $execution->source_reference_id : 0));

        if ($incidentId <= 0) {
            throw new ActionFailure('no_linked_incident', "{$execution->action_type->value} action requires a linked incident.");
        }

        $incident = Incident::query()
            ->whereKey($incidentId)
            ->where('team_id', $execution->team_id)
            ->first();

        if ($incident === null) {
            throw new ActionFailure('incident_not_in_team', "Incident {$incidentId} not found for this team.");
        }

        return $incident;
    }

    /**
     * Plantilla editable por el tenant: sólo se interpolan variables, nunca se
     * compila con Blade (sería ejecución de código en el servidor).
     *
     * @param  array<string, mixed>  $variables
     */
    private function renderTemplate(string $template, array $variables): string
    {
        return $this->interpolator->render($template, $variables);
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
