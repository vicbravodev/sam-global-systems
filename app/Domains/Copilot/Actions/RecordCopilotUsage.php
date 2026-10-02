<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\AI\Support\ModelPricing;
use App\Domains\Copilot\Data\CopilotTurn;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Throwable;

/**
 * Feeds one answered Copilot turn into the tenant's metered billing: one
 * `copilot_queries` unit plus the LLM tokens on the shared AI meters. Event
 * keys derive from the message id, so retries never double-bill.
 *
 * `executeAbandoned()` bills the tokens an agent run spent before it failed
 * and the turn fell back to the deterministic answer (which is metered as
 * the query on its own): keys derive from the question id, one per turn.
 */
class RecordCopilotUsage
{
    public const QUERIES_METER = 'copilot_queries';

    /**
     * Why the agent's tokens are billed apart from a completed answer.
     */
    public const CAUSE_FALLBACK = 'agent_error_before_output';

    public function __construct(
        private readonly RecordUsageEvent $recordUsageEvent,
        private readonly ModelPricing $pricing,
    ) {}

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

    /**
     * Bills what the turn's agent spent before it was abandoned for the
     * deterministic answer. Never throws: a billing failure must not cost the
     * operator the answer, it is logged as `record_failed` instead.
     */
    public function executeAbandoned(CopilotTurn $turn): void
    {
        $spent = $turn->spent;
        $input = [
            'team_id' => $turn->team->id,
            'cause' => self::CAUSE_FALLBACK,
            'question_id' => $turn->question->id,
            'message_id' => null,
        ];

        if ($spent->tokens() <= 0) {
            self::logPartialUsage($input, $spent->steps, $spent->model, 0, 0, 0.0);

            return;
        }

        try {
            TenantContext::for($turn->team->id, function () use ($turn, $spent): void {
                $meters = UsageMeter::query()
                    ->whereIn('code', ['ai_tokens_in', 'ai_tokens_out'])
                    ->pluck('code')
                    ->all();

                $metadata = [
                    'source' => 'copilot',
                    'cause' => self::CAUSE_FALLBACK,
                    'copilot_question_message_id' => $turn->question->id,
                    'copilot_conversation_id' => $turn->conversation->id,
                    'model' => $spent->model,
                    'steps' => $spent->steps,
                ];

                $usage = [
                    'ai_tokens_in' => $spent->usage->inputTokens,
                    'ai_tokens_out' => $spent->usage->outputTokens,
                ];

                foreach ($usage as $meter => $quantity) {
                    if ($quantity <= 0 || ! in_array($meter, $meters, true)) {
                        continue;
                    }

                    $this->recordUsageEvent->execute(
                        teamId: $turn->team->id,
                        meterCode: $meter,
                        quantity: $quantity,
                        eventKey: "{$meter}:copilot:abandoned:{$turn->question->id}",
                        metadata: $metadata,
                    );
                }
            });
        } catch (Throwable $e) {
            SystemLog::failed('copilot.turn.partial_usage', 'record_failed', $input, calc: ['steps' => $spent->steps, 'tokens_so_far' => $spent->tokens()], error: $e);

            return;
        }

        self::logPartialUsage($input, $spent->steps, $spent->model, $spent->usage->inputTokens, $spent->usage->outputTokens, $this->pricing->estimateUsageCost($spent->model, $spent->usage));
    }

    /**
     * The `copilot.turn.partial_usage` line of a turn that failed or was cut
     * short after the agent spent tokens. Never the question nor the text.
     *
     * @param  array{team_id: int, cause: string, question_id: int, message_id: int|null}  $input
     */
    public static function logPartialUsage(array $input, int $steps, ?string $model, int $inputTokens, int $outputTokens, float $cost): void
    {
        if ($inputTokens + $outputTokens <= 0) {
            SystemLog::skipped('copilot.turn.partial_usage', 'no_tokens', $input, calc: ['steps' => $steps], debug: true);

            return;
        }

        SystemLog::ok('copilot.turn.partial_usage', $input, calc: ['steps' => $steps], result: [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_estimate' => $cost,
        ]);
    }
}
