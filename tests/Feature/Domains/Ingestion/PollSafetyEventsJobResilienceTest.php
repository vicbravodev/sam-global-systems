<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Actions\DetectDuplicateEvent;
use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Ingestion\Jobs\PollSafetyEventsJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IngestionMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\Concerns\FakesSamsaraSafetyStream;
use Tests\TestCase;

/**
 * The safety-event poller against Samsara's real pagination contract: the
 * pinned startTime, cursor recovery, and never advancing state over a failed
 * request.
 */
class PollSafetyEventsJobResilienceTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use FakesSamsaraSafetyStream;
    use RefreshDatabase;

    private const string PINNED_START = '2026-09-26T08:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('rustfs');
        Queue::fake();
        Carbon::setTestNow('2026-09-27T12:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>|null  $feed
     * @param  array<string, mixed>  $attributes
     */
    private function makeIntegration(?array $feed = null, array $attributes = [], ?Team $team = null): TenantIntegration
    {
        $team ??= User::factory()->create()->currentTeam;
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create(array_merge([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
            'sync_state_json' => $feed !== null ? ['safety_events' => $feed] : null,
        ], $attributes));

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        return $integration->load('provider');
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $id, string $state = 'needsReview'): array
    {
        return [
            'id' => $id,
            'time' => '2026-09-27T11:00:00Z',
            'updatedAtTime' => '2026-09-27T11:00:05Z',
            'eventState' => $state,
            'behaviorLabels' => [['label' => 'Crash']],
            'asset' => ['id' => 'vehicle-9'],
        ];
    }

    private function poll(TenantIntegration $integration): PollSafetyEventsJob
    {
        $job = new PollSafetyEventsJob($integration);

        $job->handle(app(ProviderAdapter::class), app(IngestSafetyEvent::class));

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function feedOf(TenantIntegration $integration): array
    {
        return $integration->fresh()->sync_state_json['safety_events'];
    }

    public function test_first_poll_backfills_24h_and_pins_that_start_time(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSafetyStream([['data' => [$this->event('evt-1')], 'endCursor' => 'cursor-1']]);

        $this->poll($integration);

        $expectedStart = '2026-09-26T12:00:00+00:00';
        $this->assertSame([['startTime' => $expectedStart]], $this->sentSafetyStreamQueries());
        $this->assertSame([
            'cursor' => 'cursor-1',
            'start_time' => $expectedStart,
            'last_polled_at' => '2026-09-27T12:00:00+00:00',
        ], $this->feedOf($integration));
    }

    public function test_second_poll_resumes_with_after_and_the_same_start_time(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSafetyStream([
            ['data' => [$this->event('evt-1')]],
            ['data' => [$this->event('evt-2')]],
        ]);

        $this->poll($integration);
        $this->poll($integration->fresh());

        $queries = $this->sentSafetyStreamQueries();
        $this->assertCount(2, $queries);
        $this->assertSame($queries[0]['startTime'], $queries[1]['startTime']);
        $this->assertSame('stream-cursor-1', $queries[1]['after']);
        $this->assertSame(2, RawEvent::withoutGlobalScopes()->where('team_id', $integration->team_id)->count());
    }

    public function test_multiple_pages_are_drained_within_one_run(): void
    {
        $integration = $this->makeIntegration();
        $this->fakeSafetyStream([
            ['data' => [$this->event('evt-1')], 'hasNextPage' => true],
            ['data' => [$this->event('evt-2')], 'hasNextPage' => true],
            ['data' => [$this->event('evt-3')], 'hasNextPage' => false, 'endCursor' => 'cursor-final'],
        ]);

        $this->poll($integration);

        $queries = $this->sentSafetyStreamQueries();
        $this->assertCount(3, $queries);
        $this->assertCount(1, array_unique(array_column($queries, 'startTime')));
        $this->assertSame(
            ['evt-1', 'evt-2', 'evt-3'],
            RawEvent::withoutGlobalScopes()->where('team_id', $integration->team_id)->orderBy('id')->pluck('external_event_id')->all(),
        );
        $this->assertSame('cursor-final', $this->feedOf($integration)['cursor']);
    }

    public function test_expired_cursor_restarts_from_last_poll_minus_margin_and_persists_the_new_position(): void
    {
        $integration = $this->makeIntegration([
            'cursor' => 'cursor-expired',
            'start_time' => self::PINNED_START,
            'last_polled_at' => '2026-09-27T11:00:00+00:00',
        ]);

        // cursor-expired is not known to the fake → Samsara's 400 "invalid or expired pagination cursor".
        $this->fakeSafetyStream([['data' => [$this->event('evt-1')], 'endCursor' => 'cursor-fresh']]);

        $this->poll($integration);

        $restart = '2026-09-27T10:45:00+00:00';
        $this->assertSame([
            ['startTime' => self::PINNED_START, 'after' => 'cursor-expired'],
            ['startTime' => $restart],
        ], $this->sentSafetyStreamQueries());

        $this->assertSame([
            'cursor' => 'cursor-fresh',
            'start_time' => $restart,
            'last_polled_at' => '2026-09-27T12:00:00+00:00',
        ], $this->feedOf($integration));
        $this->assertTrue(RawEvent::withoutGlobalScopes()->where('external_event_id', 'evt-1')->exists());
        $this->assertNull($integration->fresh()->last_error_message);

        $this->assertSystemLogged('ingestion.poll.cursor_rejected', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'provider_rejected_cursor'
            && $c['input']['integration_id'] === $integration->id
            && $c['input']['http_status'] === 400
            && $c['input']['restart_from'] === $restart
            && is_string($c['input']['provider_message']));
        $this->assertCount(1, $this->systemLogEntries('ingestion.poll.cursor_rejected'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_params_differ_is_recovered_like_an_expired_cursor(): void
    {
        $integration = $this->makeIntegration([
            'cursor' => 'cursor-prev',
            'start_time' => self::PINNED_START,
            'last_polled_at' => '2026-09-27T11:58:00+00:00',
        ]);

        // The cursor exists but was produced by a different startTime.
        $this->fakeSafetyStream(
            [['data' => [], 'endCursor' => 'cursor-fresh']],
            knownCursors: ['cursor-prev' => '2026-09-01T00:00:00+00:00'],
        );

        $this->poll($integration);

        $this->assertSame('cursor-fresh', $this->feedOf($integration)['cursor']);
        $this->assertSame('2026-09-27T11:43:00+00:00', $this->feedOf($integration)['start_time']);
    }

    public function test_restart_never_reaches_further_back_than_24_hours(): void
    {
        $integration = $this->makeIntegration([
            'cursor' => 'cursor-expired',
            'start_time' => self::PINNED_START,
            'last_polled_at' => '2026-09-20T00:00:00+00:00',
        ]);
        $this->fakeSafetyStream([['data' => []]]);

        $this->poll($integration);

        $this->assertSame('2026-09-26T12:00:00+00:00', $this->sentSafetyStreamQueries()[1]['startTime']);
    }

    public function test_legacy_state_without_pinned_start_time_restarts_the_full_window_without_the_dead_cursor(): void
    {
        // The shape the buggy poller left behind (dev integration 2): a cursor
        // from August and a last_polled_at bumped on every failed poll.
        $integration = $this->makeIntegration([
            'cursor' => 'cursor-from-2026-08-11',
            'last_polled_at' => '2026-09-27T11:58:00+00:00',
        ]);
        $this->fakeSafetyStream([['data' => [$this->event('evt-1')], 'endCursor' => 'cursor-fresh']]);

        $this->poll($integration);

        $this->assertSame([['startTime' => '2026-09-26T12:00:00+00:00']], $this->sentSafetyStreamQueries());
        $this->assertSame('cursor-fresh', $this->feedOf($integration)['cursor']);
        $this->assertSame('2026-09-26T12:00:00+00:00', $this->feedOf($integration)['start_time']);
        $this->assertTrue(RawEvent::withoutGlobalScopes()->where('external_event_id', 'evt-1')->exists());
    }

    public function test_overlap_replayed_by_a_restart_is_absorbed_by_dedup(): void
    {
        $this->seed(IngestionMeterSeeder::class);

        $integration = $this->makeIntegration();
        $this->fakeSafetyStream([
            ['data' => [$this->event('evt-1')], 'endCursor' => 'cursor-1'],
            ['data' => [$this->event('evt-1')], 'endCursor' => 'cursor-2'],
        ]);

        $this->poll($integration);

        // Samsara drops the cursor; the restart window re-reads evt-1.
        $integration->update(['sync_state_json' => ['safety_events' => array_merge(
            $this->feedOf($integration),
            ['cursor' => 'cursor-gone'],
        )]]);
        $this->poll($integration->fresh());

        $raws = RawEvent::withoutGlobalScopes()->where('external_event_id', 'evt-1')->orderBy('id')->get();
        $this->assertCount(2, $raws, 'the replay is kept as an audit row');

        $detect = app(DetectDuplicateEvent::class);
        $this->assertFalse($detect->execute($raws[0]));
        $this->assertTrue($detect->execute($raws[1]), 'the replay must be flagged as a duplicate');
        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('team_id', $integration->team_id)->count(), 'the replay must not double-bill');
    }

    public function test_provider_error_does_not_advance_state_and_records_last_error(): void
    {
        $feed = [
            'cursor' => 'cursor-prev',
            'start_time' => self::PINNED_START,
            'last_polled_at' => '2026-09-27T11:58:00+00:00',
        ];
        $integration = $this->makeIntegration($feed);
        $this->fakeSafetyStream(
            [['status' => 500, 'body' => ['message' => 'Failed to execute GraphQL query.', 'requestId' => 'req-1']]],
            knownCursors: ['cursor-prev' => self::PINNED_START],
        );

        try {
            $this->poll($integration);
            $this->fail('A provider error must fail the job so the queue retries it.');
        } catch (ProviderRequestFailedException $e) {
            $this->assertSame(500, $e->status);
        }

        $fresh = $integration->fresh();
        $this->assertSame($feed, $fresh->sync_state_json['safety_events']);
        $this->assertNotNull($fresh->last_error_at);
        $this->assertStringStartsWith(PollSafetyEventsJob::ERROR_PREFIX, $fresh->last_error_message);
        $this->assertStringContainsString('HTTP 500', $fresh->last_error_message);
    }

    public function test_rate_limit_releases_with_retry_after_without_advancing_state(): void
    {
        $feed = [
            'cursor' => 'cursor-prev',
            'start_time' => self::PINNED_START,
            'last_polled_at' => '2026-09-27T11:58:00+00:00',
        ];
        $integration = $this->makeIntegration($feed);
        $this->fakeSafetyStream(
            [['status' => 429, 'body' => ['message' => 'Exceeded rate limit.'], 'headers' => ['Retry-After' => '30']]],
            knownCursors: ['cursor-prev' => self::PINNED_START],
        );

        $job = (new PollSafetyEventsJob($integration))->withFakeQueueInteractions();
        $job->handle(app(ProviderAdapter::class), app(IngestSafetyEvent::class));

        $job->assertReleased(delay: 30);
        $fresh = $integration->fresh();
        $this->assertSame($feed, $fresh->sync_state_json['safety_events']);
        $this->assertStringContainsString('HTTP 429', $fresh->last_error_message);
    }

    public function test_successful_poll_clears_its_own_previous_error(): void
    {
        $integration = $this->makeIntegration(attributes: [
            'last_error_at' => now()->subMinutes(5),
            'last_error_message' => PollSafetyEventsJob::ERROR_PREFIX.'Samsara GET /safety-events/stream respondió HTTP 500',
        ]);
        $this->fakeSafetyStream([['data' => []]]);

        $this->poll($integration);

        $fresh = $integration->fresh();
        $this->assertNull($fresh->last_error_at);
        $this->assertNull($fresh->last_error_message);
    }

    public function test_successful_poll_keeps_an_error_left_by_another_sync_path(): void
    {
        $integration = $this->makeIntegration(attributes: [
            'last_error_at' => now()->subMinutes(5),
            'last_error_message' => 'Samsara GET /fleet/vehicles respondió HTTP 401',
        ]);
        $this->fakeSafetyStream([['data' => []]]);

        $this->poll($integration);

        $this->assertSame('Samsara GET /fleet/vehicles respondió HTTP 401', $integration->fresh()->last_error_message);
    }

    public function test_recovery_poll_touches_only_its_own_tenant(): void
    {
        $other = $this->makeIntegration([
            'cursor' => 'cursor-other',
            'start_time' => self::PINNED_START,
            'last_polled_at' => '2026-09-27T11:00:00+00:00',
        ]);
        $integration = $this->makeIntegration([
            'cursor' => 'cursor-expired',
            'start_time' => self::PINNED_START,
            'last_polled_at' => '2026-09-27T11:00:00+00:00',
        ]);
        $this->fakeSafetyStream([['data' => [$this->event('evt-b')]]]);

        $this->assertNoTenantLeak($integration->team_id, fn () => $this->poll($integration));

        $this->assertSame('cursor-other', $other->fresh()->sync_state_json['safety_events']['cursor']);
        $this->assertSame(0, RawEvent::withoutGlobalScopes()->where('team_id', $other->team_id)->count());
    }

    public function test_rate_limit_logs_retry_after_and_release_delay(): void
    {
        $feed = ['cursor' => 'cursor-prev', 'start_time' => self::PINNED_START, 'last_polled_at' => '2026-09-27T11:58:00+00:00'];
        $integration = $this->makeIntegration($feed);
        $this->fakeSafetyStream(
            [['status' => 429, 'body' => ['message' => 'Exceeded rate limit.'], 'headers' => ['Retry-After' => '30']]],
            knownCursors: ['cursor-prev' => self::PINNED_START],
        );

        $job = (new PollSafetyEventsJob($integration))->withFakeQueueInteractions();
        $job->handle(app(ProviderAdapter::class), app(IngestSafetyEvent::class));

        $this->assertSystemLogged('ingestion.poll.rate_limited', fn (array $c): bool => $c['reason'] === 'provider_rate_limited'
            && $c['calc']['retry_after_seconds'] === 30
            && $c['calc']['released_for_seconds'] === 30
            && $c['calc']['fallback_seconds'] === PollSafetyEventsJob::RATE_LIMIT_FALLBACK_SECONDS
            && $c['input']['integration_id'] === $integration->id);
        $this->assertSystemNotLogged('ingestion.poll.cycle_completed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_resumed_cycle_logs_completion_without_restart(): void
    {
        $feed = ['cursor' => 'cursor-prev', 'start_time' => self::PINNED_START, 'last_polled_at' => '2026-09-27T11:58:00+00:00'];
        $integration = $this->makeIntegration($feed);
        $this->fakeSafetyStream([['data' => [$this->event('evt-1')], 'endCursor' => 'cursor-2']], knownCursors: ['cursor-prev' => self::PINNED_START]);

        $this->poll($integration);

        $this->assertSystemNotLogged('ingestion.poll.cursor_restarted');
        $this->assertSystemLogged('ingestion.poll.cycle_completed', fn (array $c): bool => $c['calc']['had_cursor'] === true
            && $c['calc']['start_time'] === self::PINNED_START
            && $c['result']['events'] === 1);
        $this->assertNoSensitiveDataLogged();
    }
}
