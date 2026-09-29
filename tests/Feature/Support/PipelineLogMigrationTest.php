<?php

namespace Tests\Feature\Support;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class PipelineLogMigrationTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private function makeIntegration(): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::factory()->create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test-token',
        ]);

        return $integration->load('provider');
    }

    public function test_a_rejected_samsara_media_request_is_logged_with_codes_and_sanitized_provider_text(): void
    {
        Http::fake(['api.samsara.com/cameras/media/retrieval*' => Http::response(['message' => 'bad vehicle, contact ops@samsara.com', 'requestId' => 'req-1'], 400)]);

        $retrievalId = app(SamsaraAdapter::class)->requestMedia($this->makeIntegration(), '100', now()->subMinutes(2), now()->subMinute());

        $this->assertNull($retrievalId);
        $ctx = $this->assertSystemLogged('samsara.media_retrieval.request_failed', fn (array $c) => $c['reason'] === 'provider_rejected');
        $this->assertSame(400, $ctx['input']['http_status']);
        $this->assertSame('bad vehicle, contact [email]', $ctx['input']['provider_message']);
        $this->assertSame('req-1', $ctx['input']['provider_request_id']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_connection_failure_is_logged_with_a_safe_error_instead_of_the_raw_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.samsara.com/cameras/media/retrieval?token=abc'));

        app(SamsaraAdapter::class)->requestMedia($this->makeIntegration(), '100', now()->subMinutes(2), now()->subMinute());

        $ctx = $this->assertSystemLogged('samsara.media_retrieval.request_failed', fn (array $c) => $c['reason'] === 'connection_failed');
        $this->assertSame(ConnectionException::class, $ctx['error']['class']);
        $this->assertStringNotContainsString('token=abc', $ctx['error']['message']);
    }
}
