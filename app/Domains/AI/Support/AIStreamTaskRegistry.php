<?php

namespace App\Domains\AI\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Per-spec §13: caches `taskId → {teamId, userId, evaluationId}` in Valkey
 * (or whichever cache store is configured) with a 10-minute TTL so that the
 * SSE stream endpoint and broadcast channel auth can resolve a streaming
 * task id back to its tenant + user without re-running the AI pipeline.
 */
class AIStreamTaskRegistry
{
    public const TTL_SECONDS = 600;

    public static function register(int $teamId, int $userId, ?int $evaluationId = null, ?int $normalizedEventId = null): string
    {
        $taskId = (string) Str::uuid();

        Cache::put(self::cacheKey($taskId), [
            'team_id' => $teamId,
            'user_id' => $userId,
            'evaluation_id' => $evaluationId,
            'normalized_event_id' => $normalizedEventId,
            'registered_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);

        return $taskId;
    }

    /**
     * @return array{team_id: int, user_id: int, evaluation_id: ?int, normalized_event_id: ?int, registered_at: string}|null
     */
    public static function resolve(string $taskId): ?array
    {
        $payload = Cache::get(self::cacheKey($taskId));

        if (! is_array($payload) || ! isset($payload['team_id'], $payload['user_id'])) {
            return null;
        }

        return $payload;
    }

    public static function forget(string $taskId): void
    {
        Cache::forget(self::cacheKey($taskId));
    }

    private static function cacheKey(string $taskId): string
    {
        return 'ai:stream:task:'.$taskId;
    }
}
