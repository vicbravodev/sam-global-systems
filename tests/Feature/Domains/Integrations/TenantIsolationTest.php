<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_it_scopes_integrations_to_current_team(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create();

        TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'My Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'secret',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $otherTeam = Team::factory()->create();
        TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Other Integration',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'other-secret',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $this->actingAs($user);

        $response = $this->getJson(
            route('api.integrations.index', ['current_team' => $team->slug]),
        );

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(
            1,
            $data,
            'Tenant isolation: only integrations belonging to the current team should be returned',
        );

        $this->assertEquals(
            'My Integration',
            $data[0]['name'],
            'The integration returned should be the one belonging to the authenticated user\'s team',
        );
    }

    public function test_it_cannot_access_another_teams_integration(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $otherTeam = Team::factory()->create();
        $provider = IntegrationProvider::factory()->create();

        $otherIntegration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Not Yours',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'secret',
            'status' => TenantIntegrationStatus::Active,
        ]);

        $response = $this->actingAs($user)->putJson(
            route('api.integrations.update', [
                'current_team' => $team->slug,
                'integration' => $otherIntegration->id,
            ]),
            ['name' => 'Hijacked'],
        );

        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Attempting to access another team's integration should return 403 or 404, got {$response->status()}",
        );

        $otherIntegration->refresh();

        $this->assertEquals(
            'Not Yours',
            $otherIntegration->name,
            'Another team\'s integration name should not be modified by an unauthorized user',
        );
    }
}
