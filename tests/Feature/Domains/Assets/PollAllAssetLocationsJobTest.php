<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Jobs\PollAllAssetLocationsJob;
use App\Domains\Assets\Jobs\PollAssetLocationsJob;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        Bus::fake([PollAssetLocationsJob::class]);

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
}
