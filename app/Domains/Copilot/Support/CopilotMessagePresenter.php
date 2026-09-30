<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;

final class CopilotMessagePresenter
{
    /**
     * A message read back from history: media card URLs are re-resolved for
     * the message's tenant (stored signed URLs expire after 30 minutes).
     * The live stream keeps `message()` so a playing clip is not reloaded.
     *
     * @return array<string, mixed>
     */
    public static function stored(CopilotMessage $message): array
    {
        $presented = self::message($message);

        if ($presented['blocks'] !== []) {
            $presented['blocks'] = app(CopilotMediaUrls::class)
                ->refreshBlocks($presented['blocks'], (int) $message->team_id, (int) $message->id);
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(CopilotMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'role' => $message->role->value,
            'content' => (string) $message->content,
            'intent' => $message->intent?->value,
            'intentLabel' => $message->intent?->label(),
            'blocks' => $message->blocks_json ?? [],
            'tools' => $message->tools_json ?? [],
            'sources' => $message->sources_json ?? [],
            'context' => $message->context_json,
            'followups' => array_values((array) ($message->context_json['followups'] ?? [])),
            'partial' => (bool) ($message->context_json['partial'] ?? false),
            'usage' => $message->role->value === 'assistant' ? [
                'model' => $message->model,
                'inputTokens' => $message->input_tokens,
                'outputTokens' => $message->output_tokens,
                'cost' => (float) $message->cost_estimate,
                'latencyMs' => $message->latency_ms,
            ] : null,
            'feedback' => $message->feedback,
            'createdAt' => $message->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function conversation(CopilotConversation $conversation): array
    {
        return [
            'id' => (int) $conversation->id,
            'title' => (string) $conversation->title,
            'isPinned' => (bool) $conversation->is_pinned,
            'messagesCount' => (int) $conversation->messages_count,
            'lastMessageAt' => $conversation->last_message_at?->toIso8601String(),
        ];
    }
}
