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
        {--team= : Team id (required: a tenant is never guessed)}
        {--name=Samsara : Display name for the integration}
        {--webhook : Also create a webhook endpoint and print its URL}
        {--secret= : Samsara-generated webhook Signing Secret to store for HMAC verification}';

    protected $description = 'Connect a team to Samsara with a real API token and test the connection';

    public function handle(TestIntegrationConnection $testConnection): int
    {
        $teamId = $this->option('team');

        // Escribe credenciales de un tenant: nunca adivinar cuál.
        if ($teamId === null || $teamId === '') {
            $this->error('The --team option is required (team id). Refusing to guess a tenant.');

            return self::FAILURE;
        }

        $team = Team::query()->find($teamId);

        if ($team === null) {
            $this->error("Team #{$teamId} not found.");

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
                'credentials_encrypted' => $this->argument('token'),
            ],
        );

        IntegrationCredential::updateOrCreate(
            ['tenant_integration_id' => $integration->id, 'key' => 'api_token'],
            ['value_encrypted' => $this->argument('token')],
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
            // Mismo criterio que la truthiness previa: '' y '0' cuentan como
            // "sin --secret" (ninguno es un Secret Key real de Samsara).
            $secret = $this->option('secret');
            $hasSecret = ! in_array($secret, [null, '', '0'], true);

            if ($hasSecret) {
                $endpoint->forceFill(['secret' => $secret, 'secret_configured_at' => now()])->save();
            }

            $url = route('webhooks.handle', ['endpoint_url' => $endpoint->url]);

            $this->newLine();
            $this->info('Webhook endpoint:');
            $this->line("  URL: {$url}");
            $this->line('  1. Register this URL in Samsara → Settings → Webhooks (HTTPS required).');
            $this->line('  2. Copy the Secret Key that Samsara generates for the webhook and store');
            $this->line('     it in SAM: re-run with --webhook --secret="<samsara-secret-key>".');

            if (! $hasSecret) {
                $this->warn('  No --secret provided yet: every webhook is rejected (secret_not_configured)');
                $this->warn('  until the real Samsara Secret Key is stored. SAM verifies X-Samsara-Signature');
                $this->warn('  (v1=<hmac>) + X-Samsara-Timestamp on every event.');
            } else {
                $this->info('  ✓ Stored Samsara Secret Key for HMAC verification.');
            }
        }

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }
}
