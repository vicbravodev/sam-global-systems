<?php

namespace Tests\Feature\Seeders;

use App\Domains\Context\Listeners\RequestIncidentMediaOnContextBuilt;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\E2eSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * La base de las pruebas E2E (tests/e2e): un pánico firmado con la Secret Key
 * sembrada recorre el pipeline sin red y el super-admin entra con 2FA.
 */
class E2eSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedE2e(): Team
    {
        $this->seed(E2eSeeder::class);

        return Team::query()->where('slug', E2eSeeder::TEAM_SLUG)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function panicPayload(): array
    {
        $now = now()->toIso8601ZuluString();

        return [
            'eventId' => (string) Str::uuid(),
            'eventTime' => $now,
            'eventType' => 'AlertIncident',
            'data' => [
                'conditions' => [[
                    'details' => ['panicButton' => [
                        'driver' => ['id' => 'e2e-driver', 'name' => 'Chofer E2E'],
                        'vehicle' => ['id' => 'e2e-vehicle', 'name' => 'E2E-01', 'serial' => 'E2E0001'],
                    ]],
                    'triggerId' => 1034,
                    'description' => 'Panic Button',
                ]],
                'isResolved' => false,
                'happenedAtTime' => $now,
                'updatedAtTime' => $now,
                'configurationId' => 'e2e-config',
            ],
        ];
    }

    private function postPanic(string $secret): void
    {
        $body = (string) json_encode($this->panicPayload());
        $timestamp = (string) now()->getTimestampMs();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$body, $secret);

        $this->call('POST', '/api/webhooks/'.E2eSeeder::WEBHOOK_URL, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SAMSARA_SIGNATURE' => $signature,
            'HTTP_X_SAMSARA_TIMESTAMP' => $timestamp,
        ], content: $body)->assertAccepted();
    }

    public function test_a_panic_signed_with_the_seeded_secret_opens_an_incident_without_network(): void
    {
        Http::preventStrayRequests();
        $team = $this->seedE2e();

        $this->postPanic(E2eSeeder::WEBHOOK_SECRET);

        $this->assertSame(1, Incident::withoutGlobalScopes()->where('team_id', $team->id)->count());
        $this->assertSame(1, Incident::withoutGlobalScopes()->count(), 'El pánico sólo abre incidente en su propio tenant.');
    }

    public function test_a_panic_signed_with_another_secret_is_rejected(): void
    {
        $this->seedE2e();

        $this->postPanic('llave-equivocada');

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
    }

    public function test_the_webhook_endpoint_belongs_to_the_seeded_tenant(): void
    {
        $team = $this->seedE2e();

        $endpoint = WebhookEndpoint::query()->where('url', E2eSeeder::WEBHOOK_URL)->sole();

        $this->assertSame($team->id, $endpoint->tenantIntegration?->team_id);
        $this->assertTrue($endpoint->hasSecret());
    }

    public function test_panic_media_auto_request_is_off(): void
    {
        $team = $this->seedE2e();

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('setting_key', RequestIncidentMediaOnContextBuilt::SETTING_KEY)
            ->sole();

        $this->assertSame(['value' => false], $setting->value_json);
    }

    public function test_the_super_admin_has_two_factor_with_the_known_secret(): void
    {
        $this->seedE2e();

        $operator = User::query()->where('email', SuperAdminSeeder::SUPER_ADMIN_EMAIL)->sole();

        $this->assertNotNull($operator->two_factor_confirmed_at);
        $this->assertTrue((new Google2FA)->verifyKey(
            decrypt((string) $operator->two_factor_secret),
            (new Google2FA)->getCurrentOtp(E2eSeeder::SUPER_ADMIN_TOTP_SECRET),
        ));

        $this->actingAs($operator)->get(route('admin.tenants.index'))->assertOk();
    }

    public function test_it_is_idempotent(): void
    {
        $this->seedE2e();
        $this->seedE2e();

        $this->assertSame(1, WebhookEndpoint::query()->where('url', E2eSeeder::WEBHOOK_URL)->count());
    }

    public function test_it_seeds_nothing_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        Model::unguarded(fn () => $this->app->make(E2eSeeder::class)->setContainer($this->app)->__invoke());

        $this->assertSame(0, User::count());
        $this->assertSame(0, WebhookEndpoint::count());
    }
}
