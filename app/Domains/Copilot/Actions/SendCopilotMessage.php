<?php

namespace App\Domains\Copilot\Actions;

use App\Contracts\AI\CopilotNarrator;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One full Copilot turn: store the question, answer it from the tenant's
 * data, narrate it, meter it and leave an audit trail.
 */
class SendCopilotMessage
{
    private const HISTORY_TURNS = 6;

    public function __construct(
        private readonly AnswerCopilotQuestion $answerQuestion,
        private readonly CopilotNarrator $narrator,
        private readonly RecordCopilotUsage $recordUsage,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * @param  list<string>  $permissions
     * @param  array{asset_id?: int|null, intent?: string|null}  $hints
     * @return array{conversation: CopilotConversation, question: CopilotMessage, answer: CopilotMessage}
     */
    public function execute(
        Team $team,
        User $user,
        array $permissions,
        string $content,
        ?CopilotConversation $conversation = null,
        array $hints = [],
        string $channel = 'page',
    ): array {
        return TenantContext::for($team->id, function () use ($team, $user, $permissions, $content, $conversation, $hints, $channel): array {
            abort_if($conversation !== null && ($conversation->team_id !== $team->id || $conversation->user_id !== $user->id), 404);

            $startedAt = hrtime(true);

            $history = $conversation ? $this->history($conversation) : [];
            $previousAssetId = $conversation ? $this->previousAssetId($conversation) : null;

            $conversation ??= CopilotConversation::query()->create([
                'team_id' => $team->id,
                'user_id' => $user->id,
                'title' => Str::limit(trim($content), 80),
                'last_message_at' => now(),
            ]);

            $question = CopilotMessage::query()->create([
                'team_id' => $team->id,
                'copilot_conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => CopilotMessageRole::User,
                'content' => $content,
                'channel' => $channel,
                'context_json' => array_filter($hints, fn ($value) => $value !== null) ?: null,
            ]);

            $answer = $this->answerQuestion->execute(
                teamId: $team->id,
                teamSlug: $team->slug,
                permissions: $permissions,
                isSuperAdmin: $user->isSuperAdmin(),
                question: $content,
                hints: $hints,
                previousAssetId: $previousAssetId,
            );

            $narration = $this->narrator->narrate($content, $answer, $history);

            $latencyMs = (int) intdiv(hrtime(true) - $startedAt, 1_000_000);

            $reply = DB::transaction(function () use ($team, $conversation, $answer, $narration, $latencyMs, $channel): CopilotMessage {
                $reply = CopilotMessage::query()->create([
                    'team_id' => $team->id,
                    'copilot_conversation_id' => $conversation->id,
                    'user_id' => null,
                    'role' => CopilotMessageRole::Assistant,
                    'content' => $narration->text,
                    'intent' => $answer->intent,
                    'channel' => $channel,
                    'context_json' => ['resolved' => $answer->resolvedContext],
                    'blocks_json' => $answer->blocks(),
                    'tools_json' => $answer->tools(),
                    'sources_json' => $answer->sources(),
                    'model' => $narration->model,
                    'input_tokens' => $narration->inputTokens,
                    'output_tokens' => $narration->outputTokens,
                    'cost_estimate' => $narration->costEstimate,
                    'latency_ms' => $latencyMs,
                ]);

                $conversation->forceFill([
                    'messages_count' => $conversation->messages_count + 2,
                    'last_message_at' => now(),
                ])->save();

                return $reply;
            });

            $this->recordUsage->execute($reply);

            $this->audit->execute(
                actorType: AuditActorType::User,
                actorId: (int) $user->id,
                action: 'copilot.query',
                category: AuditCategory::Ai,
                entityType: CopilotMessage::class,
                entityId: (int) $reply->id,
                summary: 'Consulta a SAM Copilot: '.Str::limit($content, 120),
                teamId: $team->id,
                metadata: [
                    'intent' => $answer->intent->value,
                    'asset_id' => $answer->resolvedContext['asset_id'] ?? null,
                    'tools' => array_column($answer->tools(), 'tool'),
                    'channel' => $channel,
                    'input_tokens' => $narration->inputTokens,
                    'output_tokens' => $narration->outputTokens,
                ],
                signature: 'copilot.query:'.$reply->id,
            );

            return [
                'conversation' => $conversation->refresh(),
                'question' => $question,
                'answer' => $reply,
            ];
        });
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function history(CopilotConversation $conversation): array
    {
        return CopilotMessage::query()
            ->where('team_id', $conversation->team_id)
            ->where('copilot_conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY_TURNS)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (CopilotMessage $m) => ['role' => $m->role->value, 'content' => (string) $m->content])
            ->values()
            ->all();
    }

    private function previousAssetId(CopilotConversation $conversation): ?int
    {
        $last = CopilotMessage::query()
            ->where('team_id', $conversation->team_id)
            ->where('copilot_conversation_id', $conversation->id)
            ->where('role', CopilotMessageRole::Assistant)
            ->orderByDesc('id')
            ->first(['context_json']);

        $assetId = $last?->context_json['resolved']['asset_id'] ?? null;

        return $assetId !== null ? (int) $assetId : null;
    }
}
