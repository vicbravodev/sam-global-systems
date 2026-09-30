<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotConversation;
use App\Domains\Copilot\Models\CopilotMessage;

/**
 * What the model remembers of a conversation: the last turns, each
 * assistant answer followed by the digest of the data it looked up, capped
 * at ~4 KB; plus the unit the last answer was about.
 */
final class CopilotHistory
{
    private const TURNS = 6;

    private const MAX_CHARS = 4000;

    /**
     * @return array{history: list<array{role: string, content: string}>, previousAssetId: int|null}
     */
    public function forConversation(CopilotConversation $conversation): array
    {
        $messages = CopilotMessage::query()
            ->where('team_id', $conversation->team_id)
            ->where('copilot_conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit(self::TURNS)
            ->get(['role', 'content', 'context_json'])
            ->reverse()
            ->values();

        $history = $messages->map(function (CopilotMessage $m): array {
            $digest = $m->context_json['facts_digest'] ?? null;

            return [
                'role' => $m->role->value,
                'content' => (string) $m->content.($m->role === CopilotMessageRole::Assistant && $digest ? "\n[datos consultados: {$digest}]" : ''),
            ];
        })->all();

        while (mb_strlen((string) json_encode($history, JSON_UNESCAPED_UNICODE)) > self::MAX_CHARS && count($history) > 2) {
            array_shift($history);
        }

        $assetId = $messages->where('role', CopilotMessageRole::Assistant)->last()?->context_json['resolved']['asset_id'] ?? null;

        return ['history' => array_values($history), 'previousAssetId' => $assetId !== null ? (int) $assetId : null];
    }
}
