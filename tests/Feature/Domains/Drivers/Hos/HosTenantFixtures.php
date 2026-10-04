<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Mockery;

/**
 * Tenant con integración Samsara, chofer vinculado, canales de la escalera y
 * proveedores falsos para las pruebas de insistencia HOS (PR 2).
 */
trait HosTenantFixtures
{
    protected function hosIntegration(bool $feature = true): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::where('code', 'samsara')->first() ?? IntegrationProvider::factory()->samsara()->create();
        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id, 'provider_id' => $provider->id, 'name' => 'Samsara',
            'status' => 'active', 'auth_type' => 'api_key', 'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $integration->id, 'key' => 'api_token', 'value_encrypted' => 'sk-test']);

        if ($feature) {
            TenantFeature::factory()->create(['team_id' => $integration->team_id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
        }

        return $integration->load('provider');
    }

    /**
     * @return array{0: Driver, 1: Asset}
     */
    protected function hosDriver(TenantIntegration $integration, string $externalId = '58072405', string $vehicleExternalId = '281', ?string $phone = '+5215512345678'): array
    {
        $driver = Driver::factory()->create(['team_id' => $integration->team_id, 'full_name' => 'Juan Pérez Secreto', 'phone' => $phone]);
        DriverExternalReference::factory()->create(['driver_id' => $driver->id, 'provider_id' => $integration->provider_id, 'external_id' => $externalId, 'external_type' => 'driver']);
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $integration->provider_id, 'external_id' => $vehicleExternalId, 'external_type' => 'vehicle']);

        return [$driver, $asset];
    }

    /** Canales de plataforma de la escalera por defecto. */
    protected function hosChannels(): void
    {
        NotificationChannel::factory()->samsaraDriverApp()->create();
        NotificationChannel::factory()->whatsapp()->create(['config_json' => ['from' => 'whatsapp:+14155238886']]);
        NotificationChannel::factory()->voice()->create();
    }

    /** Twilio falso: registra mensajes (WhatsApp/SMS) y llamadas. */
    protected function fakeTwilio(): \stdClass
    {
        config()->set('services.twilio.account_sid', 'AC123');
        config()->set('services.twilio.auth_token', 'tok-456');

        $sent = new \stdClass;
        $sent->messages = [];
        $sent->calls = [];

        $messenger = Mockery::mock(TwilioMessenger::class);
        $messenger->shouldReceive('createMessage')->andReturnUsing(function (string $to, array $params) use ($sent) {
            $sent->messages[] = ['to' => $to, 'params' => $params];

            return (object) ['sid' => 'SM'.bin2hex(random_bytes(16)), 'status' => 'queued', 'numSegments' => '1'];
        });
        $this->app->instance(TwilioMessenger::class, $messenger);

        $caller = Mockery::mock(TwilioVoiceCaller::class);
        $caller->shouldReceive('createCall')->andReturnUsing(function (string $to, string $from, array $params) use ($sent) {
            $sent->calls[] = ['to' => $to, 'params' => $params];

            return (object) ['sid' => 'CA'.bin2hex(random_bytes(16)), 'status' => 'queued'];
        });
        $this->app->instance(TwilioVoiceCaller::class, $caller);

        return $sent;
    }

    /** Un segundo Http::fake no reemplaza al primero: llamarlo una vez por test. */
    protected function fakeAppMessages(int $status = 200): void
    {
        Http::fake([
            'api.samsara.com/v1/fleet/messages' => Http::response($status === 200 ? ['data' => []] : ['message' => 'forbidden'], $status),
        ]);
    }
}
