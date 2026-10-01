<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;

/**
 * Feeds one answered Copilot turn into the tenant's metered billing: one
 * `copilot_queries` unit plus the LLM tokens on the shared AI meters. Event
 * keys derive from the message id, so retries never double-bill.
 */
class RecordCopilotUsage
{
    public const QUERIES_METER = 'copilot_queries';

    public function __construct(private readonly RecordUsageEvent $recordUsageEvent) {}

    public function execute(CopilotMessage $answer): void
    {
        $meters = UsageMeter::query()
            ->whereIn('code', [self::QUERIES_METER, 'ai_tokens_in', 'ai_tokens_out'])
            ->pluck('code')
            ->all();

        $metadata = [
            'source' => 'copilot',
            'copilot_message_id' => $answer->id,
            'copilot_conversation_id' => $answer->copilot_conversation_id,
            'intent' => $answer->intent?->value,
            'model' => $answer->model,
        ];

        $usage = [
            self::QUERIES_METER => 1,
            'ai_tokens_in' => $answer->input_tokens,
            'ai_tokens_out' => $answer->output_tokens,
        ];

        foreach ($usage as $meter => $quantity) {
            if ($quantity <= 0 || ! in_array($meter, $meters, true)) {
                continue;
            }

            $this->recordUsageEvent->execute(
                teamId: $answer->team_id,
                meterCode: $meter,
                quantity: $quantity,
                eventKey: "{$meter}:copilot:{$answer->id}",
                metadata: $metadata,
            );
        }
    }
}
