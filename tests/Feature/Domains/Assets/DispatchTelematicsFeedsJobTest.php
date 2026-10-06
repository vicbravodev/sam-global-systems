<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Jobs\DispatchTelematicsFeedsJob;
use App\Domains\Assets\Jobs\FollowVehicleStatsFeedJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class DispatchTelematicsFeedsJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function integration(array $attributes = [], string $providerCode = 'samsara'): TenantIntegration
    {
        $provider = IntegrationProvider::query()->where('code', $providerCode)->first()
            ?? ($providerCode === 'samsara'
                ? IntegrationProvider::factory()->samsara()->create()
                : IntegrationProvider::factory()->create(['code' => $providerCode]));

        return TenantIntegration::factory()->active()->create(array_merge([
            'team_id' => Team::factory()->create()->id,
            'provider_id' => $provider->id,
            'last_sync_at' => now()->subHour(),
        ], $attributes));
    }

    private function polled(TenantIntegration $integration, TelematicsFeed $feed, int $secondsAgo, array $attributes = []): TelematicsFeedCursor
    {
        return TelematicsFeedCursor::factory()->create(array_merge([
            'tenant_integration_id' => $integration->id,
            'feed' => $feed,
            'last_polled_at' => now()->subSeconds($secondsAgo),
        ], $attributes));
    }

    /**
     * @return list<string> "integrationId:feed" of every cycle queued
     */
    private function dispatched(): array
    {
        return Queue::pushed(FollowVehicleStatsFeedJob::class)
            ->map(fn (FollowVehicleStatsFeedJob $job) => $job->integration->id.':'.$job->feed->value)
            ->sort()->values()->all();
    }

    public function test_a_new_integration_gets_one_cycle_per_feed_on_the_telematics_queue(): void
    {
        $integration = $this->integration();

        (new DispatchTelematicsFeedsJob)->handle();

        $this->assertSame(["{$integration->id}:diagnostics", "{$integration->id}:motion"], $this->dispatched());
        Queue::assertPushedOn('telematics', FollowVehicleStatsFeedJob::class);

        $context = $this->assertSystemLogged('telematics.feeds.dispatched', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(count($this->dispatched()), $context['result']['dispatched_count']);
        $this->assertSame(1, $context['result']['integrations_count']);
        $this->assertSame(['motion_count' => 1, 'diagnostics_count' => 1, 'trailers_count' => 0], $context['result']['dispatched_count_by_feed']);
        $this->assertSame(DispatchTelematicsFeedsJob::TICK_SECONDS, $context['calc']['tick_seconds']);

        $entry = $this->systemLogEntries('telematics.feeds.dispatched')[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('telematics', $entry['channel']);

        // A platform-wide sweep: counts only, never a tenant's ids.
        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('team_id', $json);
        $this->assertStringNotContainsString('integration_id', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_tick_without_active_integrations_is_a_debug_skip(): void
    {
        (new DispatchTelematicsFeedsJob)->handle();

        $this->assertSystemLogged('telematics.feeds.dispatched', fn (array $c) => $c['reason'] === 'no_active_integrations');
        $this->assertSame('debug', $this->systemLogEntries('telematics.feeds.dispatched')[0]['level']);
    }

    public function test_an_opted_out_feed_is_counted(): void
    {
        $this->integration(['config_json' => ['sync' => ['feed_enabled' => false]]]);

        (new DispatchTelematicsFeedsJob)->handle();

        $context = $this->assertSystemLogged('telematics.feeds.dispatched', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(1, $context['result']['feed_disabled_count']);
        $this->assertSame(0, $context['result']['dispatched_count']);
        $this->assertSame('debug', $this->systemLogEntries('telematics.feeds.dispatched')[0]['level']);
    }

    public function test_only_active_samsara_integrations_with_a_catalog_are_followed(): void
    {
        $this->integration(['status' => TenantIntegrationStatus::Error]); // circuit open
        $this->integration(['last_sync_at' => null]); // assets not created yet
        $this->integration(['config_json' => ['sync' => ['feed_enabled' => false]]]); // opted out
        $this->integration([], providerCode: 'geotab'); // no stats feed

        $deletedTeam = Team::factory()->create();
        $this->integration(['team_id' => $deletedTeam->id]);
        $deletedTeam->delete();

        (new DispatchTelematicsFeedsJob)->handle();

        Queue::assertNotPushed(FollowVehicleStatsFeedJob::class);
    }

    public function test_each_feed_keeps_its_own_cadence(): void
    {
        config(['telematics.interval_seconds' => 10, 'telematics.diagnostics_interval_seconds' => 30]);

        $integration = $this->integration();
        $this->polled($integration, TelematicsFeed::Motion, secondsAgo: 6);
        $this->polled($integration, TelematicsFeed::Diagnostics, secondsAgo: 20);

        (new DispatchTelematicsFeedsJob)->handle();

        // Motion (10 s): polled 6 s ago is due within the one-tick slack.
        // Diagnostics (30 s): polled 20 s ago is not.
        $this->assertSame(["{$integration->id}:motion"], $this->dispatched());

        $context = $this->assertSystemLogged('telematics.feeds.dispatched', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(1, $context['result']['not_due_count']);
        $this->assertSame(0, $context['result']['paused_count']);
        $this->assertSame(['motion_count' => 1, 'diagnostics_count' => 0, 'trailers_count' => 0], $context['result']['dispatched_count_by_feed']);
    }

    public function test_the_default_five_second_interval_does_not_drift_to_ten(): void
    {
        $integration = $this->integration();
        // The previous cycle started 3 s late (jitter + pickup) and the next
        // tick is only 2 s after it: still due.
        $this->polled($integration, TelematicsFeed::Motion, secondsAgo: 2);
        $this->polled($integration, TelematicsFeed::Diagnostics, secondsAgo: 2);

        (new DispatchTelematicsFeedsJob)->handle();

        $this->assertSame(["{$integration->id}:motion"], $this->dispatched());
    }

    public function test_a_tenant_can_slow_its_motion_feed(): void
    {
        $slow = $this->integration(['config_json' => ['sync' => ['feed_interval_seconds' => 60]]]);
        $this->polled($slow, TelematicsFeed::Motion, secondsAgo: 30);
        $this->polled($slow, TelematicsFeed::Diagnostics, secondsAgo: 1);

        (new DispatchTelematicsFeedsJob)->handle();
        $this->assertSame([], $this->dispatched());

        $this->travel(26)->seconds();
        (new DispatchTelematicsFeedsJob)->handle();
        $this->assertContains("{$slow->id}:motion", $this->dispatched());
    }

    public function test_a_paused_feed_is_skipped_until_its_pause_ends(): void
    {
        $integration = $this->integration();
        $this->polled($integration, TelematicsFeed::Motion, secondsAgo: 60, attributes: ['paused_until' => now()->addSeconds(30)]);
        $this->polled($integration, TelematicsFeed::Diagnostics, secondsAgo: 1);

        (new DispatchTelematicsFeedsJob)->handle();
        $this->assertSame([], $this->dispatched());

        $context = $this->assertSystemLogged('telematics.feeds.dispatched', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(1, $context['result']['paused_count']);
        $this->assertSame(1, $context['result']['not_due_count']);
        $this->assertSame(0, $context['result']['dispatched_count']);
        // Nothing dispatched this tick: debug.
        $this->assertSame('debug', $this->systemLogEntries('telematics.feeds.dispatched')[0]['level']);

        $this->travel(31)->seconds();
        (new DispatchTelematicsFeedsJob)->handle();
        $this->assertContains("{$integration->id}:motion", $this->dispatched());
    }

    public function test_a_cycle_still_queued_or_running_is_not_queued_again(): void
    {
        $integration = $this->integration();

        (new DispatchTelematicsFeedsJob)->handle();
        (new DispatchTelematicsFeedsJob)->handle();

        // The unique lock of (integration, feed) holds until the cycle ends.
        Queue::assertPushedTimes(FollowVehicleStatsFeedJob::class, 2);
        $this->assertSame(["{$integration->id}:diagnostics", "{$integration->id}:motion"], $this->dispatched());
    }

    public function test_tenants_are_spread_across_the_tick_with_a_stable_offset(): void
    {
        foreach (range(1, 6) as $i) {
            $this->integration();
        }

        (new DispatchTelematicsFeedsJob)->handle();

        $delays = Queue::pushed(FollowVehicleStatsFeedJob::class)->map(fn ($job) => $job->delay);

        $this->assertTrue($delays->every(fn ($delay) => is_int($delay) && $delay >= 0 && $delay < DispatchTelematicsFeedsJob::TICK_SECONDS));
        $this->assertGreaterThan(1, $delays->unique()->count());
    }

    public function test_the_trailers_feed_only_runs_where_the_sync_found_trailers(): void
    {
        $withTrailers = $this->integration();
        $without = $this->integration();
        Asset::factory()->trailer()->create([
            'team_id' => $withTrailers->team_id,
            'source_integration_id' => $withTrailers->id,
        ]);
        Asset::factory()->create([
            'team_id' => $without->team_id,
            'source_integration_id' => $without->id,
        ]);

        (new DispatchTelematicsFeedsJob)->handle();

        $trailerCycles = array_values(array_filter($this->dispatched(), fn (string $cycle) => str_ends_with($cycle, ':trailers')));
        $this->assertSame(["{$withTrailers->id}:trailers"], $trailerCycles);

        $context = $this->assertSystemLogged('telematics.feeds.dispatched', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(1, $context['result']['no_trailers_count']);
        $this->assertSame(1, $context['result']['dispatched_count_by_feed']['trailers_count']);
    }
}
