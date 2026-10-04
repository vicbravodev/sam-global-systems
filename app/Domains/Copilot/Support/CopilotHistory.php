<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;
use Illuminate\Support\Collection;

/**
 * What the model remembers of a conversation: the last turns, oldest first.
 * The latest answer goes in full with the digest of the data it looked up;
 * older answers are condensed (their opening and a short digest) so the
 * thread stays in memory for several turns instead of only the last one.
 * The whole history is capped at ~10 KB by dropping the oldest messages,
 * never below the last two. Also returns the unit the last answer was about
 * and the questions already asked, so suggestions never repeat them.
 */
final class CopilotHistory
{
    private const MESSAGES = 12;

    private const MAX_CHARS = 10000;

    private const OLD_ANSWER_CHARS = 600;

    private const OLD_DIGEST_CHARS = 400;

    /**
     * @return array{history: list<array{role: string, content: string}>, previousAssetId: int|null, askedQuestions: list<string>}
     */
    public function forConversation(CopilotConversation $conversation): array
    {
        /** @var Collection<int, CopilotMessage> $messages */
        $messages = CopilotMessage::query()
            ->where('team_id', $conversation->team_id)
            ->where('copilot_conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::MESSAGES)
            ->get(['id', 'role', 'content', 'context_json'])
            ->reverse()
            ->values();

        $lastAnswerId = $messages->where('role', CopilotMessageRole::Assistant)->last()?->id;

        $history = $messages->map(fn (CopilotMessage $m): array => [
            'role' => $m->role->value,
            'content' => $m->role === CopilotMessageRole::Assistant
                ? $this->answer($m, latest: $m->id === $lastAnswerId)
                : $m->content,
        ])->all();

        while (mb_strlen((string) json_encode($history, JSON_UNESCAPED_UNICODE)) > self::MAX_CHARS && count($history) > 2) {
            array_shift($history);
        }

        $assetId = $messages->where('role', CopilotMessageRole::Assistant)->last()?->context_json['resolved']['asset_id'] ?? null;

        return [
            'history' => array_values($history),
            'previousAssetId' => $assetId !== null ? (int) $assetId : null,
            'askedQuestions' => array_values($messages->where('role', CopilotMessageRole::User)->map(fn (CopilotMessage $m): string => $m->content)->all()),
        ];
    }

    private function answer(CopilotMessage $message, bool $latest): string
    {
        $digest = $message->context_json['facts_digest'] ?? null;
        $content = $latest ? $message->content : $this->cut($message->content, self::OLD_ANSWER_CHARS);

        if (! is_string($digest) || $digest === '') {
            return $content;
        }

        $digest = $latest ? $digest : $this->cut($digest, self::OLD_DIGEST_CHARS);

        return $content."\n[datos consultados: {$digest}]";
    }

    private function cut(string $text, int $chars): string
    {
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars)).'…' : $text;
    }
}
