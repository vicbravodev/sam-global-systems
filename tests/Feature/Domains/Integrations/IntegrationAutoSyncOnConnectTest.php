<?php

namespace Tests\Feature\Domains\Integrations;

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

    public function test_connecting_an_integration_kicks_off_catalog_sync(): void
    {
        Bus::fake([SyncIntegrationJob::class]);

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

        // Positions follow on their own: the telematics feed picks the
        // integration up once this first catalog sync has created its assets.
        Bus::assertDispatched(SyncIntegrationJob::class);

        $this->assertDatabaseHas('integration_sync_jobs', [
            'type' => SyncType::Full->value,
        ]);
    }
}
