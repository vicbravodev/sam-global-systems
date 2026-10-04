<?php

namespace App\Domains\Copilot\Actions;

use App\Domains\Copilot\Data\CopilotTurn;
use App\Domains\Copilot\Data\CopilotTurnOutcome;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Copilot\Queries\CopilotQuotaQuery;
use App\Domains\Copilot\Streaming\CopilotStreamProtocol;
use App\Domains\Copilot\Streaming\CopilotStreamState;
use App\Domains\Copilot\Streaming\DeterministicCopilotParts;
use App\Domains\Copilot\Support\CopilotAnswerText;
use App\Domains\Copilot\Support\CopilotMessagePresenter;
use App\Domains\Copilot\Support\CopilotTurnCollector;
use App\Models\Team;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Generator;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * One Copilot turn streamed to the browser (Vercel data stream protocol):
 * prepare it, stream the agent through CopilotStreamProtocol and store the
 * answer when the stream completes — or answer deterministically, in the
 * same protocol, without a provider key or when the provider fails before
 * the first token. A failure after it, or a browser that leaves, stores
 * what was sent as a partial answer. Each turn is stored exactly once.
 *
 * Billing follows what the agent spent (`CopilotTurn::$spent`, step by
 * step): a completed answer bills the stream's usage; a partial one, the
 * tokens of the steps completed so far, on the stored message; a fallback,
 * the abandoned agent's tokens apart (RecordCopilotUsage::executeAbandoned).
 *
 * The body runs after the controller returned, so every closure that
 * touches the DB enters the turn's tenant on its own.
 */
class StreamCopilotTurn
{
    public function __construct(
        private readonly PrepareCopilotTurn $prepare,
        private readonly RunCopilotAgentTurn $agent,
        private readonly RunDeterministicCopilotTurn $deterministic,
        private readonly FinishCopilotTurn $finish,
        private readonly CopilotQuotaQuery $quota,
        private readonly CopilotStreamState $state,
        private readonly RecordCopilotUsage $recordUsage,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @param  array{asset_id?: int|null, intent?: string|null}  $hints
     */
    public function execute(
        Team $team,
        User $user,
        array $permissions,
        string $content,
        ?CopilotConversation $conversation = null,
        array $hints = [],
        string $channel = 'page',
    ): Response {
        $turn = TenantContext::for($team->id, fn (): CopilotTurn => $this->prepare->execute($team, $user, $permissions, $content, $conversation, $hints, $channel));

        $this->state->conversationId = $turn->conversation->id;

        // Keep running when the browser leaves, so the partial answer is stored.
        ignore_user_abort(true);

        if (! RunCopilotAgentTurn::available()) {
            SystemLog::skipped('copilot.turn.fallback', 'no_provider_key', ['team_id' => $team->id], debug: true);

            return DeterministicCopilotParts::response($this->deterministicParts($turn, withStart: true));
        }

        [$agent, $prompt] = TenantContext::for($team->id, fn (): array => [$this->agent->agentFor($turn), $this->agent->prompt($turn)]);

        $stream = $agent->stream($prompt);

        $stream->then(function (StreamedAgentResponse $response) use ($turn): void {
            if ($this->state->persisted) {
                return;
            }

            $this->store($turn, new CopilotTurnOutcome(
                mode: 'agent',
                text: CopilotAnswerText::compose($response->steps->pluck('text'), $turn->collector),
                model: $response->meta->model,
                usage: $response->usage,
                steps: $response->steps->count(),
                intent: $turn->collector->primaryIntent(),
                partial: false,
                firstTextMs: $this->state->firstTextMs,
            ));
        });

        $stream->catch(function (Throwable $e) use ($turn): void {
            // Before the first token the protocol answers deterministically.
            if (! $this->state->textStarted || $this->state->persisted) {
                return;
            }

            $reply = $this->storePartial($turn);

            SystemLog::failed('copilot.turn.failed', 'agent_error_mid_stream', ['team_id' => $turn->team->id, 'message_id' => $reply->id], calc: ['tokens_so_far' => $turn->spent->tokens()], error: $e);

            $this->logPartialUsage($turn, 'agent_error_mid_stream', $reply);
        });

        return (new CopilotStreamProtocol(
            state: $this->state,
            collector: $turn->collector,
            fallback: function (Throwable $e, bool $withStart) use ($turn): Generator {
                SystemLog::degraded('copilot.turn.fallback', 'agent_error_before_output', ['team_id' => $turn->team->id], calc: ['tokens_so_far' => $turn->spent->tokens()], error: $e);

                // What the abandoned agent already spent is billed apart.
                $this->recordUsage->executeAbandoned($turn);

                // Start over: nothing a half-run agent collected reaches the answer.
                $turn->collector = new CopilotTurnCollector($turn->askedQuestions);

                yield from $this->deterministicParts($turn, $withStart);
            },
            onDisconnect: function () use ($turn): void {
                if ($this->state->persisted) {
                    return;
                }

                $reply = $this->storePartial($turn);

                SystemLog::skipped('copilot.turn.failed', 'client_disconnected', ['team_id' => $turn->team->id, 'message_id' => $reply->id], calc: ['tokens_so_far' => $turn->spent->tokens()]);

                $this->logPartialUsage($turn, 'client_disconnected', $reply);
            },
            startedAt: $turn->startedAt,
        ))->response($stream);
    }

    /**
     * Runs the deterministic turn, stores it and returns its parts.
     *
     * @return Generator<array<string, mixed>>
     */
    private function deterministicParts(CopilotTurn $turn, bool $withStart): Generator
    {
        $outcome = TenantContext::for($turn->team->id, fn (): CopilotTurnOutcome => $this->deterministic->execute($turn));

        $this->store($turn, $outcome);

        return DeterministicCopilotParts::parts($turn->collector, $outcome, (array) $this->state->messagePayload, $withStart, $turn->conversation->id);
    }

    /**
     * What was sent so far, stored as a partial agent answer with the tokens
     * of the steps completed so far: the answer's own usage events bill them
     * (keys from its message id), so a retry never bills them twice.
     */
    private function storePartial(CopilotTurn $turn): CopilotMessage
    {
        return $this->store($turn, new CopilotTurnOutcome(
            mode: 'agent',
            text: CopilotAnswerText::compose([$this->state->text], $turn->collector),
            model: $turn->spent->model,
            usage: $turn->spent->usage,
            steps: $turn->spent->steps,
            intent: $turn->collector->primaryIntent(),
            partial: true,
            firstTextMs: $this->state->firstTextMs,
        ));
    }

    private function logPartialUsage(CopilotTurn $turn, string $cause, CopilotMessage $reply): void
    {
        RecordCopilotUsage::logPartialUsage(
            ['team_id' => $turn->team->id, 'cause' => $cause, 'question_id' => $turn->question->id, 'message_id' => $reply->id],
            $turn->spent->steps,
            $reply->model,
            $reply->input_tokens,
            $reply->output_tokens,
            (float) $reply->cost_estimate,
        );
    }

    /**
     * Stores, meters and audits the answer and keeps the payload of the
     * `data-copilot-message` part. At most once per turn: `persisted` is set
     * before storing, so a store that fails halfway (the answer committed,
     * then metering or the audit threw) never falls back nor stores again.
     */
    private function store(CopilotTurn $turn, CopilotTurnOutcome $outcome): CopilotMessage
    {
        $this->state->persisted = true;

        try {
            return TenantContext::for($turn->team->id, function () use ($turn, $outcome): CopilotMessage {
                $reply = $this->finish->execute($turn, $outcome);

                $this->state->messagePayload = [
                    'answer' => CopilotMessagePresenter::message($reply),
                    'question' => CopilotMessagePresenter::message($turn->question),
                    'conversation' => CopilotMessagePresenter::conversation($turn->conversation->refresh()),
                    'quota' => $this->quota->forTeam($turn->team->id),
                ];

                return $reply;
            });
        } catch (Throwable $e) {
            SystemLog::failed('copilot.turn.failed', 'persist_failed', [
                'team_id' => $turn->team->id,
                'message_id' => $this->storedAnswerId($turn),
                'mode' => $outcome->mode,
                'partial' => $outcome->partial,
            ], error: $e);

            throw $e;
        }
    }

    /**
     * The answer a failed store may still have committed, if any.
     */
    private function storedAnswerId(CopilotTurn $turn): ?int
    {
        try {
            return TenantContext::for($turn->team->id, fn (): ?int => CopilotMessage::query()
                ->where('team_id', $turn->team->id)
                ->where('copilot_conversation_id', $turn->conversation->id)
                ->where('role', CopilotMessageRole::Assistant)
                ->where('id', '>', $turn->question->id)
                ->value('id'));
        } catch (Throwable) {
            return null;
        }
    }
}
