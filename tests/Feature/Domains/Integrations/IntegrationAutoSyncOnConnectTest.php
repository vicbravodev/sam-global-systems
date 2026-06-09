<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Assets\Jobs\PollAssetLocationsJob;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Jobs\SyncIntegrationJob;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class IntegrationAutoSyncOnConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_connecting_an_integration_kicks_off_catalog_sync_and_location_poll(): void
    {
        Bus::fake([SyncIntegrationJob::class, PollAssetLocationsJob::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $response = $this->actingAs($user)->postJson(
            route('api.integrations.store', ['current_team' => $team->slug]),
            [
                'provider_id' => $provider->id,
                'name' => 'My Samsara Connection',
                'auth_type' => 'api_key',
                'credentials' => 'super-secret-api-key',
            ],
        );

        $response->assertCreated();

        Bus::assertDispatched(SyncIntegrationJob::class);
        Bus::assertDispatched(PollAssetLocationsJob::class);

        $this->assertDatabaseHas('integration_sync_jobs', [
            'type' => SyncType::Full->value,
        ]);
    }
}
