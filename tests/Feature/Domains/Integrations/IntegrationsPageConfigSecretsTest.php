<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    /**
     * PR #130 follow-up: the JSON API (store/update/index) used to echo the
     * full `config_json` back, secrets included.
     */
    public function test_api_responses_only_expose_public_config_keys(): void
    {
        // Connecting dispatches the auto-sync; keep the test off the network.
        Event::fake([IntegrationConnected::class, IntegrationStatusChanged::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $store = $this->actingAs($user)->postJson(
            route('api.integrations.store', ['current_team' => $team->slug]),
            [
                'provider_id' => $provider->id,
                'name' => 'Samsara',
                'auth_type' => 'api_key',
                'credentials' => self::SECRET,
                'config' => ['sync' => ['catalog_interval_minutes' => 30], 'api_token' => self::SECRET],
            ],
        );

        $store->assertCreated()
            ->assertJsonPath('data.config_json', ['sync' => ['catalog_interval_minutes' => 30]]);
        $this->assertStringNotContainsString(self::SECRET, $store->getContent());

        $integrationId = $store->json('data.id');

        $update = $this->putJson(
            route('api.integrations.update', ['current_team' => $team->slug, 'integration' => $integrationId]),
            ['name' => 'Samsara 2', 'config' => ['sync' => ['catalog_interval_minutes' => 60]]],
        );

        $update->assertOk()
            ->assertJsonPath('data.config_json', ['sync' => ['catalog_interval_minutes' => 60]]);
        $this->assertStringNotContainsString(self::SECRET, $update->getContent());

        $index = $this->getJson(route('api.integrations.index', ['current_team' => $team->slug]));

        $index->assertOk()->assertJsonPath('data.0.config_json', ['sync' => ['catalog_interval_minutes' => 60]]);
        $this->assertStringNotContainsString(self::SECRET, $index->getContent());

        // The hidden key is still stored server-side.
        $this->assertSame(self::SECRET, TenantIntegration::query()->findOrFail($integrationId)->config_json['api_token']);
    }
}
