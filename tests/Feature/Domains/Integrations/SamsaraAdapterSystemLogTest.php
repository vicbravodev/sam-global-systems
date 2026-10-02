<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Fase 6 del log narrativo: lo que el adaptador de Samsara tragaba en silencio
 * (probar conexión, `/gateways`, ubicación en vivo) deja su código y su
 * motivo. Nunca el token.
 */
class SamsaraAdapterSystemLogTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private const TOKEN = 'samsara_api_TOKEN_secreto_123';

    private function makeIntegration(?string $token = self::TOKEN): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::query()->where('code', 'samsara')->first() ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
        ]);

        if ($token !== null) {
            IntegrationCredential::factory()->create([
                'tenant_integration_id' => $integration->id,
                'key' => 'api_token',
                'value_encrypted' => $token,
            ]);
        }

        return $integration->load('provider');
    }

    private function assertTokenNeverLogged(): void
    {
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_test_connection_logs_success_and_each_failure_reason(): void
    {
        $integration = $this->makeIntegration();
        $adapter = app(SamsaraAdapter::class);

        Http::fakeSequence('api.samsara.com/*')
            ->push(['data' => []], 200)
            ->push(['message' => 'unauthorized'], 401)
            ->push(['message' => 'boom'], 503);

        $this->assertTrue($adapter->testConnection($integration)['success']);
        $this->assertFalse($adapter->testConnection($integration)['success']);
        $this->assertFalse($adapter->testConnection($integration)['success']);

        $ok = $this->assertSystemLogged('samsara.test_connection.succeeded');
        $this->assertSame(['integration_id' => $integration->id, 'team_id' => $integration->team_id], $ok['input']);
        $this->assertSystemLogged('samsara.test_connection.failed', fn (array $c) => $c['reason'] === 'unauthorized' && $c['input']['http_status'] === 401);
        $this->assertSystemLogged('samsara.test_connection.failed', fn (array $c) => $c['reason'] === 'http_error' && $c['input']['http_status'] === 503);

        $this->assertFalse($adapter->testConnection($this->makeIntegration(token: null))['success']);
        $this->assertSystemLogged('samsara.test_connection.failed', fn (array $c) => $c['reason'] === 'no_token' && $c['outcome'] === 'skipped');

        $this->assertTokenNeverLogged();
    }

    public function test_an_unreachable_samsara_is_logged_on_test_connection(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7'));

        $this->assertFalse(app(SamsaraAdapter::class)->testConnection($this->makeIntegration())['success']);

        $ctx = $this->assertSystemLogged('samsara.test_connection.failed', fn (array $c) => $c['reason'] === 'connection_failed');
        $this->assertArrayHasKey('error', $ctx);
        $this->assertTokenNeverLogged();
    }

    public function test_a_failed_gateways_page_is_logged_and_discards_the_partial_listing(): void
    {
        Http::fakeSequence('api.samsara.com/gateways*')
            ->push([
                'data' => [[
                    'serial' => 'GW-1',
                    'model' => 'VG54',
                    'asset' => ['id' => '100'],
                    'connectionStatus' => ['healthStatus' => 'healthy', 'lastConnected' => '2026-10-01T10:00:00Z'],
                ]],
                'pagination' => ['endCursor' => 'c1', 'hasNextPage' => true],
            ], 200)
            ->push(['message' => 'boom'], 500);

        $integration = $this->makeIntegration();

        $this->assertSame([], app(SamsaraAdapter::class)->fetchDeviceConnectivity($integration));

        $ctx = $this->assertSystemLogged('samsara.gateways.failed', fn (array $c) => $c['reason'] === 'http_error');
        $this->assertSame(['integration_id' => $integration->id, 'http_status' => 500], $ctx['input']);
        $this->assertSame(1, $ctx['calc']['pages_read']);
        $this->assertTokenNeverLogged();
    }

    public function test_live_location_failures_say_why(): void
    {
        $integration = $this->makeIntegration();
        $adapter = app(SamsaraAdapter::class);

        Http::fakeSequence('api.samsara.com/fleet/vehicles/locations*')
            ->push(['message' => 'boom'], 502)
            ->push(['data' => [['id' => '100']]], 200)
            ->push(['data' => [['id' => '100', 'location' => ['time' => '2026-10-01T10:00:00Z']]]], 200);

        $this->assertNull($adapter->fetchLiveLocation($integration, '100'));
        $this->assertSystemLogged('samsara.live_location.failed', fn (array $c) => $c['reason'] === 'http_error' && $c['input']['http_status'] === 502);

        $this->assertNull($adapter->fetchLiveLocation($integration, '100'));
        $this->assertSystemLogged('samsara.live_location.failed', fn (array $c) => $c['reason'] === 'no_position' && $c['calc']['record_present'] === true);

        $this->assertNull($adapter->fetchLiveLocation($integration, '100'));
        $this->assertSystemLogged('samsara.live_location.failed', fn (array $c) => $c['reason'] === 'no_coordinates' && $c['input']['vehicle_id'] === '100');

        $this->assertNull($adapter->fetchLiveLocation($this->makeIntegration(token: null), '100'));
        $this->assertSystemLogged('samsara.live_location.failed', fn (array $c) => $c['reason'] === 'no_token');

        $this->assertTokenNeverLogged();
    }

    public function test_a_live_location_timeout_is_logged_as_connection_failed(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 28: timed out'));

        $this->assertNull(app(SamsaraAdapter::class)->fetchLiveLocation($this->makeIntegration(), '100'));

        $ctx = $this->assertSystemLogged('samsara.live_location.failed', fn (array $c) => $c['reason'] === 'connection_failed');
        $this->assertSame((int) config('services.samsara.live_location_timeout', 3), $ctx['calc']['timeout_seconds']);
        $this->assertTokenNeverLogged();
    }
}
