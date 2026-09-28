<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Events\FleetPositionsUpdatedBroadcast;
use App\Domains\Assets\Events\FleetTelemetryUpdatedBroadcast;
use App\Domains\Assets\Jobs\BackfillVehicleStatsJob;
use App\Domains\Assets\Jobs\FollowVehicleStatsFeedJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class FollowVehicleStatsFeedJobTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private const FEED_URL = 'api.samsara.com/fleet/vehicles/stats/feed*';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([
            FleetPositionsUpdatedBroadcast::class,
            FleetTelemetryUpdatedBroadcast::class,
            IntegrationStatusChanged::class,
        ]);

        // Wednesday 16:00 in Mexico City: inside the schedule factory's
        // Mon–Fri 08:00–18:00 window unless a test moves the clock.
        Carbon::setTestNow(Carbon::parse('2026-06-10 22:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function integration(?Team $team = null): TenantIntegration
    {
        $provider = IntegrationProvider::query()->where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => ($team ?? Team::factory()->create())->id,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
            'last_sync_at' => now()->subHour(),
        ]);

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-'.$integration->id,
        ]);

        return $integration->load('provider');
    }

    private function linkAsset(TenantIntegration $integration, string $externalId): Asset
    {
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);

        AssetExternalReference::factory()->create([
            'asset_id' => $asset->id,
            'provider_id' => $integration->provider_id,
            'external_id' => $externalId,
        ]);

        return $asset;
    }

    /**
     * @param  list<array<string, mixed>>  $vehicles
     * @return array<string, mixed>
     */
    private function page(array $vehicles, string $endCursor = 'cursor-1', bool $hasNextPage = false): array
    {
        return ['data' => $vehicles, 'pagination' => ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage]];
    }

    /**
     * @return array<string, mixed>
     */
    private function gps(float $mph, int $secondsAgo, float $lat = 19.43, float $lng = -99.13): array
    {
        return [
            'latitude' => $lat,
            'longitude' => $lng,
            'speedMilesPerHour' => $mph,
            'headingDegrees' => 90,
            'time' => now()->subSeconds($secondsAgo)->toIso8601ZuluString(),
        ];
    }

    private function cycle(TenantIntegration $integration, TelematicsFeed $feed = TelematicsFeed::Motion): void
    {
        app()->call([new FollowVehicleStatsFeedJob($integration, $feed), 'handle']);
    }

    private function cursor(TenantIntegration $integration, TelematicsFeed $feed = TelematicsFeed::Motion): TelematicsFeedCursor
    {
        return TelematicsFeedCursor::withoutGlobalScopes()
            ->where('tenant_integration_id', $integration->id)
            ->where('feed', $feed)
            ->sole();
    }

    public function test_a_cycle_stores_every_point_moves_the_live_position_and_broadcasts_once(): void
    {
        $integration = $this->integration();
        $truck = $this->linkAsset($integration, '100');
        $van = $this->linkAsset($integration, '200');

        Http::fake([self::FEED_URL => Http::response($this->page([
            ['id' => '100', 'gps' => [$this->gps(30, 20), $this->gps(35, 10, 19.44)]],
            ['id' => '200', 'gps' => [$this->gps(0, 15, 20.0, -100.0)]],
            ['id' => '999', 'gps' => [$this->gps(50, 5)]], // no asset yet: skipped
        ], endCursor: 'cursor-1'))]);

        $this->cycle($integration);

        $this->assertSame(3, AssetLocationSnapshot::query()->count());

        $truck->refresh();
        $this->assertEqualsWithDelta(19.44, $truck->last_latitude, 0.0001);
        $this->assertTrue($truck->last_location_at->equalTo(now()->subSeconds(10)));

        $cursor = $this->cursor($integration);
        $this->assertSame('cursor-1', $cursor->end_cursor);
        $this->assertTrue($cursor->last_data_at->equalTo(now()->subSeconds(10)));
        $this->assertSame(0, $cursor->consecutive_failures);

        // One socket message for the whole tenant, one entry per moved asset.
        Event::assertDispatchedTimes(FleetPositionsUpdatedBroadcast::class, 1);
        Event::assertDispatched(FleetPositionsUpdatedBroadcast::class, fn (FleetPositionsUpdatedBroadcast $b) => $b->teamId === $integration->team_id
            && collect($b->positions)->pluck('asset_id')->sort()->values()->all() === collect([$truck->id, $van->id])->sort()->values()->all());
    }

    public function test_the_cursor_advances_and_is_sent_on_the_next_cycle(): void
    {
        $integration = $this->integration();
        $this->linkAsset($integration, '100');

        Http::fake([self::FEED_URL => Http::sequence()
            ->push($this->page([['id' => '100', 'gps' => [$this->gps(30, 10)]]], 'cursor-1'))
            ->push($this->page([], 'cursor-2'))]);

        $this->cycle($integration);
        $this->cycle($integration);

        Http::assertSent(fn (Request $request) => ! str_contains($request->url(), 'after='));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=cursor-1'));
        $this->assertSame('cursor-2', $this->cursor($integration)->end_cursor);
    }

    public function test_it_drains_the_feed_while_there_are_more_pages_but_caps_a_cycle(): void
    {
        config(['telematics.max_pages_per_cycle' => 3]);

        $integration = $this->integration();
        $this->linkAsset($integration, '100');

        $calls = 0;
        Http::fake([self::FEED_URL => function () use (&$calls) {
            $calls++;

            return Http::response($this->page([['id' => '100', 'gps' => [$this->gps(30, 100 - $calls)]]], "cursor-{$calls}", hasNextPage: true));
        }]);

        $this->cycle($integration);

        // Capped: the rest waits for the next cycle, from the saved cursor.
        $this->assertSame(3, $calls);
        $this->assertSame('cursor-3', $this->cursor($integration)->end_cursor);
        $this->assertSame(3, AssetLocationSnapshot::query()->count());
    }

    public function test_replaying_a_window_stores_nothing_twice(): void
    {
        $integration = $this->integration();
        $asset = $this->linkAsset($integration, '100');

        $page = $this->page([[
            'id' => '100',
            'gps' => [$this->gps(30, 10)],
            'fuelPercents' => [['time' => now()->subSeconds(10)->toIso8601ZuluString(), 'value' => 54]],
        ]]);
        Http::fake([self::FEED_URL => Http::response($page)]);

        $this->cycle($integration);

        // A crash before the cursor saved, or a backfill overlapping the feed:
        // the same points arrive again.
        $this->cursor($integration)->forceFill(['end_cursor' => null])->save();
        $this->cycle($integration);

        $this->assertSame(1, AssetLocationSnapshot::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(1, AssetTelemetrySnapshot::query()->where('asset_id', $asset->id)->count());
    }

    public function test_unchanged_readings_are_dropped_and_changed_ones_broadcast(): void
    {
        $integration = $this->integration();
        $asset = $this->linkAsset($integration, '100');

        $fuel = fn (int $value, int $secondsAgo) => ['time' => now()->subSeconds($secondsAgo)->toIso8601ZuluString(), 'value' => $value];

        Http::fake([self::FEED_URL => Http::response($this->page([[
            'id' => '100',
            'fuelPercents' => [$fuel(54, 30), $fuel(54, 20), $fuel(53, 10)],
            'engineStates' => [['time' => now()->subSeconds(25)->toIso8601ZuluString(), 'value' => 'On']],
        ]]))]);

        $this->cycle($integration);

        // 54 → 54 is not news; 54 → 53 is.
        $this->assertSame(
            [54, 53],
            AssetTelemetrySnapshot::query()->where('asset_id', $asset->id)->where('telemetry_type', 'fuel')->orderBy('recorded_at')->get()
                ->map(fn ($s) => (int) $s->data_json['value'])->all(),
        );

        Event::assertDispatched(FleetTelemetryUpdatedBroadcast::class, fn (FleetTelemetryUpdatedBroadcast $b) => $b->assets[0]['asset_id'] === $asset->id
            && $b->assets[0]['readings']['fuel']['value'] === 53.0
            && $b->assets[0]['readings']['ignition']['value'] === 'On');
    }

    public function test_the_motion_state_tracks_moving_and_stopped_and_never_rewinds(): void
    {
        $integration = $this->integration();
        $asset = $this->linkAsset($integration, '100');

        Http::fake([self::FEED_URL => Http::sequence()
            ->push($this->page([['id' => '100', 'gps' => [$this->gps(40, 60), $this->gps(0, 50), $this->gps(0, 40)]]], 'c1'))
            // A late page with an older moving point must not un-stop the unit.
            ->push($this->page([['id' => '100', 'gps' => [$this->gps(40, 55)]]], 'c2'))]);

        $this->cycle($integration);

        $asset->refresh();
        $this->assertTrue($asset->last_moving_at->equalTo(now()->subSeconds(60)));
        $this->assertTrue($asset->stopped_since->equalTo(now()->subSeconds(50)));

        $this->cycle($integration);

        $asset->refresh();
        $this->assertTrue($asset->stopped_since->equalTo(now()->subSeconds(50)));
        $this->assertTrue($asset->last_location_at->equalTo(now()->subSeconds(40)));
        $this->assertSame(4, AssetLocationSnapshot::query()->count()); // still kept as history
    }

    /**
     * Seen live on asset 107: parked for over an hour on the same spot (±2 m)
     * while the GPS read 0.5–2.95 km/h. Each phantom speed used to reopen the
     * stop and re-alert it every ten minutes.
     */
    public function test_gps_jitter_of_a_parked_unit_never_reopens_its_stop(): void
    {
        $integration = $this->integration();
        $asset = $this->linkAsset($integration, '107');

        $at = fn (float $mph, int $secondsAgo, float $lat, float $lng) => [
            'latitude' => $lat, 'longitude' => $lng, 'speedMilesPerHour' => $mph,
            'time' => now()->subSeconds($secondsAgo)->toIso8601ZuluString(),
        ];

        Http::fake([self::FEED_URL => Http::sequence()
            // Drives in, stops.
            ->push($this->page([['id' => '107', 'gps' => [
                $at(40, 900, 20.6900, -105.2385),
                $at(0, 800, 20.69759, -105.23855),
            ]]], 'c1'))
            // Parked: phantom 1.8 mph (~2.9 km/h) and even a 4 mph (~6.4 km/h)
            // spike, all within a few metres.
            ->push($this->page([['id' => '107', 'gps' => [
                $at(1.8, 600, 20.69760, -105.23855),
                $at(4.0, 400, 20.69761, -105.23859),
                $at(0.4, 200, 20.69757, -105.23852),
            ]]], 'c2'))
            // Really leaves: fast and 300 m away.
            ->push($this->page([['id' => '107', 'gps' => [
                $at(30, 10, 20.7003, -105.2385),
            ]]], 'c3'))]);

        $this->cycle($integration);
        $stoppedSince = $asset->fresh()->stopped_since;
        $movedAt = $asset->fresh()->last_moving_at;
        $this->assertNotNull($stoppedSince);

        $this->cycle($integration);
        $asset->refresh();
        $this->assertTrue($asset->stopped_since->equalTo($stoppedSince));
        $this->assertTrue($asset->last_moving_at->equalTo($movedAt));

        $this->cycle($integration);
        $asset->refresh();
        $this->assertNull($asset->stopped_since);
        $this->assertTrue($asset->last_moving_at->equalTo(now()->subSeconds(10)));
    }

    public function test_a_rate_limit_pauses_this_feed_for_retry_after_without_moving_the_cursor(): void
    {
        $integration = $this->integration();
        TelematicsFeedCursor::factory()->create(['tenant_integration_id' => $integration->id, 'end_cursor' => 'cursor-1']);

        Http::fake([self::FEED_URL => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '2.4'])]);

        $this->cycle($integration);

        $cursor = $this->cursor($integration);
        $this->assertSame('cursor-1', $cursor->end_cursor);
        $this->assertSame(1, $cursor->consecutive_failures);
        // Rounded up to whole seconds: never resume before the provider allows.
        $this->assertTrue($cursor->paused_until->equalTo(now()->addSeconds(3)));

        // While paused, a cycle does not even call the provider.
        $this->cycle($integration);
        Http::assertSentCount(1);
    }

    public function test_server_errors_back_off_exponentially(): void
    {
        $integration = $this->integration();

        Http::fake([self::FEED_URL => Http::response('down', 503)]);

        $this->cycle($integration);
        $this->assertTrue($this->cursor($integration)->paused_until->equalTo(now()->addSeconds(5)));

        $this->travel(6)->seconds();
        $this->cycle($integration);
        $this->assertTrue($this->cursor($integration)->paused_until->equalTo(now()->addSeconds(10)));
        $this->assertSame(2, $this->cursor($integration)->consecutive_failures);
    }

    public function test_an_invalid_token_opens_the_circuit_for_that_tenant_only(): void
    {
        $broken = $this->integration();
        $healthy = $this->integration();
        $this->linkAsset($healthy, '200');

        Http::fake([self::FEED_URL => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer sk-'.$broken->id)
            ? Http::response(['message' => 'unauthorized'], 401)
            : Http::response($this->page([['id' => '200', 'gps' => [$this->gps(20, 5)]]]))]);

        $this->cycle($broken);
        $this->cycle($healthy);

        $this->assertSame(TenantIntegrationStatus::Error, $broken->fresh()->status);
        Event::assertDispatched(IntegrationStatusChanged::class, fn (IntegrationStatusChanged $e) => $e->integrationId === $broken->id
            && $e->status === TenantIntegrationStatus::Error->value);

        $this->assertSame(TenantIntegrationStatus::Active, $healthy->fresh()->status);
        $this->assertSame(1, AssetLocationSnapshot::query()->count());
    }

    public function test_a_rejected_cursor_restarts_the_feed_and_backfills_the_gap(): void
    {
        $integration = $this->integration();
        $lastDataAt = now()->subHours(2);

        TelematicsFeedCursor::factory()->create([
            'tenant_integration_id' => $integration->id,
            'end_cursor' => 'expired',
            'last_data_at' => $lastDataAt,
        ]);

        Http::fake([self::FEED_URL => Http::response(['message' => 'invalid cursor'], 400)]);

        $this->cycle($integration);

        $this->assertNull($this->cursor($integration)->end_cursor);
        Queue::assertPushed(BackfillVehicleStatsJob::class, fn (BackfillVehicleStatsJob $job) => $job->integration->is($integration)
            && $job->feed === TelematicsFeed::Motion
            && $job->from->equalTo($lastDataAt));
    }

    public function test_pages_committed_before_a_failure_are_kept_and_published(): void
    {
        $integration = $this->integration();
        $this->linkAsset($integration, '100');

        Http::fake([self::FEED_URL => Http::sequence()
            ->push($this->page([['id' => '100', 'gps' => [$this->gps(30, 10)]]], 'cursor-1', hasNextPage: true))
            ->push(['message' => 'slow down'], 429, ['Retry-After' => '1'])]);

        $this->cycle($integration);

        $this->assertSame('cursor-1', $this->cursor($integration)->end_cursor);
        $this->assertSame(1, AssetLocationSnapshot::query()->count());
        Event::assertDispatchedTimes(FleetPositionsUpdatedBroadcast::class, 1);
    }

    public function test_a_cycle_never_touches_another_tenants_assets(): void
    {
        $other = $this->integration();
        $otherAsset = $this->linkAsset($other, 'theirs');

        $mine = $this->integration();
        $this->linkAsset($mine, 'mine');

        // The feed of my org mentions their external id: it must not resolve.
        Http::fake([self::FEED_URL => Http::response($this->page([
            ['id' => 'mine', 'gps' => [$this->gps(30, 10)]],
            ['id' => 'theirs', 'gps' => [$this->gps(30, 10)]],
        ]))]);

        $this->assertNoTenantLeak($mine->team_id, fn () => $this->cycle($mine));

        $this->assertSame(0, AssetLocationSnapshot::query()->where('asset_id', $otherAsset->id)->count());
        $this->assertNull($otherAsset->fresh()->last_location_at);
    }

    public function test_moving_outside_operating_hours_raises_the_event_within_the_cycle(): void
    {
        // Sunday 03:00 UTC: closed for the Mon–Fri schedule.
        Carbon::setTestNow(Carbon::parse('2026-06-14 03:00:00', 'UTC'));

        $integration = $this->integration();
        $asset = $this->linkAsset($integration, '100');
        TenantScheduleProfile::factory()->create(['team_id' => $integration->team_id, 'is_active' => true]);

        Http::fake([self::FEED_URL => Http::response($this->page([['id' => '100', 'gps' => [$this->gps(40, 10)]]]))]);

        $this->cycle($integration);

        $event = RawEvent::withoutGlobalScopes()->sole();
        $this->assertSame('after_hours_movement', $event->event_type_raw);
        $this->assertSame($asset->id, $event->payload_json['internal']['asset_id']);
    }

    public function test_the_diagnostics_feed_has_its_own_cursor(): void
    {
        $integration = $this->integration();

        Http::fake([self::FEED_URL => Http::response($this->page([], 'diag-1'))]);

        $this->cycle($integration, TelematicsFeed::Diagnostics);

        $this->assertSame('diag-1', $this->cursor($integration, TelematicsFeed::Diagnostics)->end_cursor);
        $this->assertSame(0, TelematicsFeedCursor::withoutGlobalScopes()->where('feed', TelematicsFeed::Motion)->count());
    }

    public function test_it_is_unique_per_integration_and_feed_on_the_telematics_queue(): void
    {
        $job = new FollowVehicleStatsFeedJob(TenantIntegration::factory()->make(['id' => 7]), TelematicsFeed::Motion);

        $this->assertSame('telematics', $job->queue);
        $this->assertSame('telematics-feed:7:motion', $job->uniqueId());
        $this->assertSame(1, $job->tries);
    }
}
