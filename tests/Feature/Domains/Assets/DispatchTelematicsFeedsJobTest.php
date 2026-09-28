<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Jobs\DispatchTelematicsFeedsJob;
use App\Domains\Assets\Jobs\FollowVehicleStatsFeedJob;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchTelematicsFeedsJobTest extends TestCase
{
    use RefreshDatabase;

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
}
