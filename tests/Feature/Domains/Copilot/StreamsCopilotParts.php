<?php

namespace Tests\Feature\Domains\Copilot;

use Illuminate\Testing\TestResponse;

/**
 * Reads the SSE body of the Copilot stream endpoint as the list of
 * Vercel protocol parts the browser receives.
 */
trait StreamsCopilotParts
{
    /**
     * The Accept header the browser sends (spec §8); the route still answers
     * throttle, policy, 404 and validation errors as JSON.
     */
    protected const STREAM_ACCEPT = 'text/event-stream';

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function streamAs(mixed $user, string $teamSlug, array $payload): TestResponse
    {
        return $this->actingAs($user)->post("/{$teamSlug}/copilot/stream", $payload, ['Accept' => self::STREAM_ACCEPT]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function parts(TestResponse $response): array
    {
        return collect(explode("\n\n", $response->streamedContent()))
            ->map(fn (string $frame) => trim($frame))
            ->filter(fn (string $frame) => str_starts_with($frame, 'data: ') && $frame !== 'data: [DONE]')
            ->map(fn (string $frame) => json_decode(substr($frame, 6), true))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return list<string>
     */
    protected function types(array $parts): array
    {
        return array_column($parts, 'type');
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>|null
     */
    protected function firstPart(array $parts, string $type): ?array
    {
        return collect($parts)->firstWhere('type', $type);
    }

    /**
     * Neither the question nor the name it carries reaches the system log.
     */
    protected function assertQuestionNeverLogged(string $question): void
    {
        foreach ($this->systemLogEntries() as $entry) {
            $json = (string) json_encode($entry['context'], JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString($question, $json, "[{$entry['code']}] registró la pregunta.");
            $this->assertStringNotContainsString('Juan', $json, "[{$entry['code']}] registró un nombre.");
        }
    }
}
