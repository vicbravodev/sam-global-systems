<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The integrations page used to ship the raw `config_json` to the browser,
 * including any provider secret stored there. Only allowlisted keys may
 * reach the Inertia props.
 */
class IntegrationsPageConfigSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'sk_live_do-not-leak-4f9a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_config_secrets_never_reach_the_inertia_props(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
            'credentials_encrypted' => self::SECRET,
            'config_json' => [
                'sync' => ['enabled' => true, 'catalog_interval_minutes' => 30],
                'api_token' => self::SECRET,
                'webhook_secret' => self::SECRET,
                'nested' => ['client_secret' => self::SECRET],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('integrations.index', ['current_team' => $team->slug]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('integrations/index')
            ->where('integrations.0.config', ['sync' => ['enabled' => true, 'catalog_interval_minutes' => 30]])
        );

        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_config_without_public_keys_is_sent_as_null(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'config_json' => ['api_token' => self::SECRET],
        ]);

        $response = $this->actingAs($user)->get(route('integrations.index', ['current_team' => $team->slug]));

        $response->assertInertia(fn (Assert $page) => $page->where('integrations.0.config', null));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_saving_the_edited_public_config_keeps_hidden_keys(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $team->id,
            'config_json' => [
                'sync' => ['catalog_interval_minutes' => 30],
                'api_token' => self::SECRET,
            ],
        ]);

        $this->actingAs($user)->putJson(
            route('api.integrations.update', ['current_team' => $team->slug, 'integration' => $integration->id]),
            ['name' => 'Samsara', 'config' => ['sync' => ['catalog_interval_minutes' => 60]]],
        )->assertOk();

        $this->assertSame(
            ['api_token' => self::SECRET, 'sync' => ['catalog_interval_minutes' => 60]],
            $integration->refresh()->config_json,
        );
    }
}
