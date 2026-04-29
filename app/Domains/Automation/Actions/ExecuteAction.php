<?php

namespace App\Domains\Automation\Actions;

use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Enums\ActionLogType;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Events\ActionExecuted;
use App\Domains\Automation\Events\ActionFailed;
use App\Domains\Automation\Models\ActionExecution;
use App\Domains\Automation\Models\ActionExecutionLog;
use Illuminate\Support\Facades\Http;
use Throwable;

class ExecuteAction
{
    public function __construct(
        private ResolveActionTemplate $resolveActionTemplate,
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
            // CreateTicket / AssignIncident / Escalate / UpdateAssetState / RequestHumanReview
            // are still stubs: the Incidents and Assets domains expose models, but no domain
            // service yet bridges automation actions to those write paths.
            ActionType::CreateTicket,
            ActionType::AssignIncident,
            ActionType::Escalate,
            ActionType::UpdateAssetState,
            ActionType::RequestHumanReview => [
                'stub' => true,
                'reason' => 'handler_deferred',
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
     * @return array<string, mixed>
     */
    private function sendNotification(ActionExecution $execution): array
    {
        return [
            'stub' => true,
            'reason' => 'notifications_domain_deferred',
            'channel' => $execution->action_type->value,
            'target_type' => $execution->target_type,
            'target_reference' => $execution->target_reference,
        ];
    }
}
