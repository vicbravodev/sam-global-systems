<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderCursorRejectedException;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\RedactSensitiveLogData;
use App\Support\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Poll the provider's safety-event feed for a single integration and push
 * every event through the raw-event funnel.
 *
 * Feed state lives in `sync_state_json.safety_events`:
 * - `cursor`: Samsara's `endCursor` to resume from.
 * - `start_time`: the exact `startTime` string of the request that produced
 *   the cursor. Samsara requires it on every page, byte-identical, so it is
 *   pinned for as long as the cursor lives.
 * - `last_polled_at`: the last SUCCESSFUL poll.
 *
 * State is persisted only AFTER the events have been stored, and never on a
 * failed request, so a crash or a provider error re-reads the same window on
 * the next run and the `safety:{id}:{eventState}` dedup keys absorb the
 * replay without duplicate side effects.
 *
 * When Samsara rejects the cursor (expired, invalid, or the parameters no
 * longer match), the feed restarts from `last_polled_at` minus a safety
 * margin, clamped to the last {@see BACKFILL_HOURS} hours. State written
 * before `start_time` was tracked cannot be resumed at all, and its
 * `last_polled_at` was advanced even on failed polls, so it restarts from the
 * full backfill window.
 *
 * Unique per integration so overlapping scheduler ticks never double-poll.
 */
class PollSafetyEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int BACKFILL_HOURS = 24;

    /**
     * Overlap re-read when restarting after a rejected cursor; dedup absorbs it.
     */
    public const int RESTART_MARGIN_MINUTES = 15;

    /**
     * Fallback delay for a 429 without a usable `Retry-After` header.
     */
    public const int RATE_LIMIT_FALLBACK_SECONDS = 60;

    /**
     * Prefix of the `last_error_message` this poller writes, so a successful
     * poll only clears its own error and never one left by another sync path.
     */
    public const string ERROR_PREFIX = 'Safety events poll: ';

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /** One page per run; must stay below the `redis` retry_after (240 s). */
    public int $timeout = 200;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        IngestSafetyEvent $ingestSafetyEvent,
    ): void {
        // Un ciclo de poll es una operación: cada evento que ingiere abre su
        // propia traza, enlazada a ésta por parent_trace_id.
        PipelineTrace::beginOperation($this->integration->team_id, 'samsara');

        $state = $this->integration->sync_state_json ?? [];
        $feed = (array) ($state['safety_events'] ?? []);

        $cursor = $this->nonEmptyString($feed['cursor'] ?? null);
        $startTime = $this->nonEmptyString($feed['start_time'] ?? null);

        if ($cursor === null || $startTime === null) {
            $cursorBefore = $cursor;
            $startBefore = $startTime;
            $cursor = null;
            $startTime = $this->restartFrom($feed);

            SystemLog::ok('ingestion.poll.cursor_restarted', input: ['integration_id' => $this->integration->id], calc: ['restart_from' => $startTime, 'had_cursor' => $cursorBefore !== null, 'had_start_time' => $startBefore !== null, 'backfill_hours' => self::BACKFILL_HOURS, 'restart_margin_minutes' => self::RESTART_MARGIN_MINUTES]);
        }

        try {
            $result = $this->fetch($providerAdapter, $feed, $cursor, $startTime);
        } catch (\Throwable $e) {
            $this->recordError($e);

            if ($e instanceof ProviderRequestFailedException && $e->isRateLimited()) {
                $releaseFor = $e->retryAfterSeconds ?? self::RATE_LIMIT_FALLBACK_SECONDS;

                SystemLog::degraded('ingestion.poll.rate_limited', reason: 'provider_rate_limited', input: ['integration_id' => $this->integration->id], calc: ['retry_after_seconds' => $e->retryAfterSeconds, 'fallback_seconds' => self::RATE_LIMIT_FALLBACK_SECONDS, 'released_for_seconds' => $releaseFor]);

                $this->release($releaseFor);

                return;
            }

            throw $e;
        }

        foreach ($result['events'] as $payload) {
            $ingestSafetyEvent->execute($this->integration, (array) $payload);
        }

        $state['safety_events'] = [
            'cursor' => $result['cursor'],
            'start_time' => $result['start_time'] ?? $startTime,
            'last_polled_at' => now()->toIso8601String(),
        ];

        $attributes = ['sync_state_json' => $state];

        if (str_starts_with((string) $this->integration->last_error_message, self::ERROR_PREFIX)) {
            $attributes['last_error_at'] = null;
            $attributes['last_error_message'] = null;
        }

        $this->integration->update($attributes);

        SystemLog::ok('ingestion.poll.cycle_completed', input: ['integration_id' => $this->integration->id], calc: ['start_time' => $startTime, 'had_cursor' => $cursor !== null], result: ['events' => count($result['events']), 'has_more' => $result['has_more'], 'next_cursor_present' => $result['cursor'] !== null]);
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'integration_id' => $this->integration->id,
        ]);

        $this->recordError($exception);
    }

    public function uniqueId(): string
    {
        return "poll-safety-events-{$this->integration->id}";
    }

    /**
     * Fetch from the stored position; if the provider rejects the cursor,
     * drop it and restart once from a fresh start time.
     *
     * @param  array<string, mixed>  $feed
     * @return array{events: array<int, array<string, mixed>>, cursor: string|null, start_time: string|null, has_more: bool}
     */
    private function fetch(ProviderAdapter $providerAdapter, array $feed, ?string $cursor, string $startTime): array
    {
        try {
            return $providerAdapter->fetchSafetyEvents($this->integration, $cursor, $startTime);
        } catch (ProviderCursorRejectedException $e) {
            if ($cursor === null) {
                throw $e;
            }

            $restartFrom = $this->restartFrom($feed);

            SystemLog::degraded('ingestion.poll.cursor_rejected', reason: 'provider_rejected_cursor', input: ['integration_id' => $this->integration->id, 'http_status' => $e->status, 'provider_message' => Str::limit(RedactSensitiveLogData::sanitize((string) $e->providerMessage), 200), 'restart_from' => $restartFrom]);

            return $providerAdapter->fetchSafetyEvents($this->integration, null, $restartFrom);
        }
    }

    /**
     * Start time for a fresh feed: `last_polled_at` minus the margin, never
     * further back than the backfill window. A legacy state (no pinned
     * `start_time`) had `last_polled_at` bumped even on failed polls, so it
     * cannot be trusted and the full window is re-read instead.
     *
     * @param  array<string, mixed>  $feed
     */
    private function restartFrom(array $feed): string
    {
        $floor = now()->subHours(self::BACKFILL_HOURS);
        $lastPolledAt = $this->nonEmptyString($feed['last_polled_at'] ?? null);
        $trustworthy = $this->nonEmptyString($feed['start_time'] ?? null) !== null;

        if ($lastPolledAt === null || ! $trustworthy) {
            return $floor->toIso8601String();
        }

        try {
            $candidate = Carbon::parse($lastPolledAt)->subMinutes(self::RESTART_MARGIN_MINUTES);
        } catch (\Throwable) {
            return $floor->toIso8601String();
        }

        if ($candidate->greaterThan(now())) {
            $candidate = now()->subMinutes(self::RESTART_MARGIN_MINUTES);
        }

        return $candidate->max($floor)->toIso8601String();
    }

    private function recordError(\Throwable $exception): void
    {
        $this->integration->update([
            'last_error_at' => now(),
            'last_error_message' => self::ERROR_PREFIX.mb_substr($exception->getMessage(), 0, 500),
        ]);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
