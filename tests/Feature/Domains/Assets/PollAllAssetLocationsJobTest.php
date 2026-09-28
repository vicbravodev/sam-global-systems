<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\PollAllAssetLocationsJob;
use App\Domains\Assets\Jobs\PollAssetConnectivityJob;
use App\Domains\Assets\Jobs\PollAssetLocationsJob;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PollAllAssetLocationsJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeIntegration(array $attributes = []): TenantIntegration
    {
        return TenantIntegration::withoutGlobalScopes()->create(array_merge([
            'team_id' => Team::factory()->create()->id,
            'provider_id' => IntegrationProvider::factory()->create()->id,
            'name' => 'Integration',
            'status' => TenantIntegrationStatus::Active,
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'x',
        ], $attributes));
    }

    public function test_it_dispatches_a_poll_only_for_due_active_integrations(): void
    {
        Bus::fake([PollAssetLocationsJob::class, PollAssetConnectivityJob::class]);

        $due = $this->makeIntegration(['last_location_poll_at' => null]);
        $this->makeIntegration(['last_location_poll_at' => now()]); // polled just now — not due
        $this->makeIntegration(['status' => TenantIntegrationStatus::Inactive]); // inactive — excluded
        $this->makeIntegration([
            'last_location_poll_at' => null,
            'config_json' => ['sync' => ['poll_locations' => false]], // opted out
        ]);

        (new PollAllAssetLocationsJob)->handle();

        Bus::assertDispatchedTimes(PollAssetLocationsJob::class, 1);
        Bus::assertDispatched(
            PollAssetLocationsJob::class,
            fn (PollAssetLocationsJob $job) => $job->integration->id === $due->id,
        );

        // The device heartbeat rides the same cadence, for the same integrations.
        Bus::assertDispatchedTimes(PollAssetConnectivityJob::class, 1);
        Bus::assertDispatched(
            PollAssetConnectivityJob::class,
            fn (PollAssetConnectivityJob $job) => $job->integration->id === $due->id,
        );
    }

    public function test_it_respects_a_per_integration_location_interval(): void
    {
        Bus::fake([PollAssetLocationsJob::class]);

        // Polled 3 minutes ago but configured for a 10-minute interval — not due.
        $this->makeIntegration([
            'last_location_poll_at' => now()->subMinutes(3),
            'config_json' => ['sync' => ['location_interval_minutes' => 10]],
        ]);

        (new PollAllAssetLocationsJob)->handle();

        Bus::assertNotDispatched(PollAssetLocationsJob::class);
    }

    public function test_the_default_one_minute_interval_survives_the_jitter_of_the_tick(): void
    {
        Bus::fake([PollAssetLocationsJob::class, PollAssetConnectivityJob::class]);

        // The previous poll started a few seconds after its tick, so the next
        // tick finds it slightly less than a minute old. It must still be due,
        // or a one-minute cadence silently degrades to two.
        $this->makeIntegration(['last_location_poll_at' => now()->subSeconds(52)]);

        (new PollAllAssetLocationsJob)->handle();

        Bus::assertDispatchedTimes(PollAssetLocationsJob::class, 1);
    }

    public function test_device_connectivity_is_polled_on_its_own_slower_cadence(): void
    {
        Bus::fake([PollAssetLocationsJob::class, PollAssetConnectivityJob::class]);
        Cache::flush();

        $integration = $this->makeIntegration(['last_location_poll_at' => null]);

        // Positions every minute, the gateway heartbeat once per window.
        foreach (range(1, 3) as $minute) {
            (new PollAllAssetLocationsJob)->handle();
            $this->finishPolls($integration);
            $this->travel(1)->minutes();
        }

        Bus::assertDispatchedTimes(PollAssetLocationsJob::class, 3);
        Bus::assertDispatchedTimes(PollAssetConnectivityJob::class, 1);

        $this->finishPolls($integration);
        $this->travel(PollAllAssetLocationsJob::CONNECTIVITY_INTERVAL_MINUTES)->minutes();
        (new PollAllAssetLocationsJob)->handle();

        Bus::assertDispatchedTimes(PollAssetConnectivityJob::class, 2);
    }

    /**
     * Stands in for the faked polls having run: a finished job releases its
     * unique lock, which Bus::fake never does on its own.
     */
    private function finishPolls(TenantIntegration $integration): void
    {
        $lock = new UniqueLock(Cache::driver());
        $lock->release(new PollAssetLocationsJob($integration));
        $lock->release(new PollAssetConnectivityJob($integration));

        $integration->update(['last_location_poll_at' => null]);
    }
}
