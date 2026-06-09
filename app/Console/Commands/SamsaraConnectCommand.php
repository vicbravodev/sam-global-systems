<?php

namespace App\Console\Commands;

use App\Domains\Integrations\Actions\TestIntegrationConnection;
use App\Domains\Integrations\Enums\AuthType;
use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\IntegrationProviderType;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\Team;
use Illuminate\Console\Command;

/**
 * Convenience command to wire a Samsara integration for a team from a real API
 * token, validate the connection, and print the webhook URL to register in the
 * Samsara dashboard. Intended for local/manual testing of the pipeline.
 */
class SamsaraConnectCommand extends Command
{
    protected $signature = 'samsara:connect
        {token : Samsara API token}
        {--team= : Team id (defaults to the first team)}
        {--name=Samsara : Display name for the integration}
        {--webhook : Also create a webhook endpoint and print its URL}
        {--secret= : Samsara-generated webhook Signing Secret to store for HMAC verification}';

    protected $description = 'Connect a team to Samsara with a real API token and test the connection';

    public function handle(TestIntegrationConnection $testConnection): int
    {
        $team = $this->resolveTeam();

        if (! $team) {
            $this->error('No team found. Create a team/user first (e.g. php artisan migrate:fresh --seed).');

            return self::FAILURE;
        }

        $provider = IntegrationProvider::firstOrCreate(
            ['code' => 'samsara'],
            [
                'name' => 'Samsara',
                'type' => IntegrationProviderType::Telematics,
                'status' => IntegrationProviderStatus::Active,
                'capabilities_json' => ['gps', 'diagnostics', 'driver_behavior'],
            ],
        );

        $integration = TenantIntegration::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $team->id, 'provider_id' => $provider->id],
            [
                'name' => (string) $this->option('name'),
                'status' => TenantIntegrationStatus::Pending,
                'auth_type' => AuthType::ApiKey,
                'credentials_encrypted' => (string) $this->argument('token'),
            ],
        );

        IntegrationCredential::updateOrCreate(
            ['tenant_integration_id' => $integration->id, 'key' => 'api_token'],
            ['value_encrypted' => (string) $this->argument('token')],
        );

        $this->info("Integration #{$integration->id} ready for team #{$team->id} ({$team->name}).");

        $this->line('Testing connection to Samsara...');
        $result = $testConnection->execute($integration->refresh());

        if ($result['success']) {
            $this->info('✓ '.$result['message']);
        } else {
            $this->error('✗ '.$result['message']);
        }

        if ($this->option('webhook')) {
            $endpoint = WebhookEndpoint::firstOrCreate(
                ['tenant_integration_id' => $integration->id],
                ['status' => 'active'],
            );

            // Samsara generates the webhook Secret Key itself (it cannot be set
            // via the API/dashboard), so the real signing secret must be copied
            // back into SAM. Use --secret=... once the webhook exists in Samsara.
            if ($secret = $this->option('secret')) {
                $endpoint->update(['secret' => (string) $secret]);
            }

            $url = route('webhooks.handle', ['endpoint_url' => $endpoint->url]);

            $this->newLine();
            $this->info('Webhook endpoint:');
            $this->line("  URL: {$url}");
            $this->line('  1. Register this URL in Samsara → Settings → Webhooks (HTTPS required).');
            $this->line('  2. Copy the Secret Key that Samsara generates for the webhook and store');
            $this->line('     it in SAM: re-run with --webhook --secret="<samsara-secret-key>".');

            if (! $this->option('secret')) {
                $this->warn('  No --secret provided yet: HMAC verification will fail until the real');
                $this->warn('  Samsara Secret Key is stored. SAM verifies X-Samsara-Signature');
                $this->warn('  (v1=<hmac>) + X-Samsara-Timestamp on every event.');
            } else {
                $this->info('  ✓ Stored Samsara Secret Key for HMAC verification.');
            }
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveTeam(): ?Team
    {
        $teamId = $this->option('team');

        if ($teamId) {
            return Team::find($teamId);
        }

        return Team::query()->orderBy('id')->first();
    }
}
