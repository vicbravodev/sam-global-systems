<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stateful fake of `GET /safety-events/stream` that enforces Samsara's real
 * pagination contract (verified against the live API), so a test cannot pass
 * against a contract Samsara does not honour:
 *
 * - `startTime` is required on every request → 400 "startTime is missing".
 * - `after` must be a cursor this fake issued (or one registered as known)
 *   → 400 "invalid or expired pagination cursor".
 * - `startTime` on an `after` request must be byte-identical to the one that
 *   produced the cursor → 400 "Parameters differ from previous paginated request".
 *
 * Valid requests consume `$responses` in order. Each entry is either a page
 * (`data`, `hasNextPage`, optional `endCursor`) or a forced failure
 * (`status`, optional `body` / `headers`). Once exhausted, empty last pages
 * are served.
 */
trait FakesSamsaraSafetyStream
{
    /**
     * @param  list<array<string, mixed>>  $responses
     * @param  array<string, string>  $knownCursors  cursor => the startTime that produced it
     */
    protected function fakeSafetyStream(array $responses, array $knownCursors = []): void
    {
        $issued = $knownCursors;
        $queue = $responses;
        $served = 0;

        Http::fake([
            'api.samsara.com/safety-events/stream*' => function (Request $request) use (&$issued, &$queue, &$served) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                $startTime = $query['startTime'] ?? null;
                $after = $query['after'] ?? null;

                if (! is_string($startTime) || $startTime === '') {
                    return $this->samsaraError(400, 'startTime is missing');
                }

                if ($after !== null) {
                    if (! is_string($after) || ! array_key_exists($after, $issued)) {
                        return $this->samsaraError(400, 'invalid or expired pagination cursor');
                    }

                    if ($issued[$after] !== $startTime) {
                        return $this->samsaraError(400, 'Parameters differ from previous paginated request');
                    }
                }

                $next = array_shift($queue) ?? ['data' => [], 'hasNextPage' => false];

                if (isset($next['status'])) {
                    return Http::response(
                        $next['body'] ?? ['message' => 'Samsara error', 'requestId' => 'req-fake'],
                        $next['status'],
                        $next['headers'] ?? [],
                    );
                }

                $served++;
                $cursor = $next['endCursor'] ?? "stream-cursor-{$served}";
                $issued[$cursor] = $startTime;

                return Http::response([
                    'data' => $next['data'] ?? [],
                    'pagination' => [
                        'endCursor' => $cursor,
                        'hasNextPage' => $next['hasNextPage'] ?? false,
                    ],
                ], 200);
            },
        ]);
    }

    /**
     * Query params of every request the fake received, in order.
     *
     * @return list<array<string, mixed>>
     */
    protected function sentSafetyStreamQueries(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/safety-events/stream'))
            ->map(function (array $pair): array {
                parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

                return $query;
            })
            ->values()
            ->all();
    }

    private function samsaraError(int $status, string $message): mixed
    {
        return Http::response(['message' => $message, 'requestId' => 'req-fake'], $status);
    }
}
