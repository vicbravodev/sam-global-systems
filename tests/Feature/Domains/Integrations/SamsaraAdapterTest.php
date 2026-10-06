<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Adapters\ProviderAdapterManager;
use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class SamsaraAdapterTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    private function makeIntegration(?string $token = 'sk-test-token'): TenantIntegration
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'pending',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);

        if ($token !== null) {
            IntegrationCredential::create([
                'tenant_integration_id' => $integration->id,
                'key' => 'api_token',
                'value_encrypted' => $token,
            ]);
        }

        return $integration->load('provider');
    }

    public function test_test_connection_succeeds_with_valid_token(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['data' => [['id' => '1']]], 200),
        ]);

        $result = app(SamsaraAdapter::class)->testConnection($this->makeIntegration());

        $this->assertTrue($result['success']);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-test-token'));
    }

    public function test_test_connection_reports_rejected_token(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $result = app(SamsaraAdapter::class)->testConnection($this->makeIntegration());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('401', $result['message']);
    }

    public function test_test_connection_fails_without_token(): void
    {
        $result = app(SamsaraAdapter::class)->testConnection($this->makeIntegration(token: null));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No hay token de API', $result['message']);
    }

    public function test_sync_maps_vehicles_and_drivers(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response([
                'data' => [
                    ['id' => '100', 'name' => 'Truck 1', 'vin' => 'VIN100', 'cameraSerial' => 'CM-123', 'make' => 'Volvo'],
                    ['id' => '101', 'name' => 'Truck 2', 'vin' => 'VIN101'],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
            'api.samsara.com/fleet/drivers*' => Http::response([
                'data' => [['id' => '200', 'name' => 'Jane Doe', 'username' => 'jane']],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $result = app(SamsaraAdapter::class)->sync($this->makeIntegration(), 'full');

        $this->assertCount(2, $result['assets']);
        $this->assertSame('100', $result['assets'][0]['external_id']);
        $this->assertSame('VIN100', $result['assets'][0]['vin']);

        // A paired CM dashcam surfaces as has_camera metadata — this gates the
        // panic-media auto-request listener and the context signals.
        $this->assertTrue($result['assets'][0]['metadata']['has_camera']);
        $this->assertSame('CM-123', $result['assets'][0]['metadata']['camera_serial']);
        $this->assertSame('Volvo', $result['assets'][0]['metadata']['make']);
        $this->assertFalse($result['assets'][1]['metadata']['has_camera']);
        $this->assertArrayNotHasKey('camera_serial', $result['assets'][1]['metadata']);

        $this->assertCount(1, $result['drivers']);
        $this->assertSame('200', $result['drivers'][0]['external_id']);
        $this->assertSame(3, $result['records_processed']);
        $this->assertSame([], $result['events']);
    }

    public function test_sync_reports_the_gateway_and_camera_installed_on_each_vehicle(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response([
                'data' => [
                    [
                        'id' => '100',
                        'name' => 'Truck 1',
                        'gateway' => ['model' => 'VG34', 'serial' => 'GHWJPVPM8J'],
                        'cameraSerial' => 'CM-123',
                    ],
                    // Gateway serial only at the root (older payload shape).
                    ['id' => '101', 'name' => 'Truck 2', 'serial' => 'G9WR4JKTJZ'],
                    // No hardware reported at all.
                    ['id' => '102', 'name' => 'Truck 3'],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
            'api.samsara.com/fleet/drivers*' => Http::response([
                'data' => [],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $assets = app(SamsaraAdapter::class)->sync($this->makeIntegration(), 'full')['assets'];

        $this->assertSame([
            ['device_type' => 'gateway', 'external_device_id' => 'GHWJPVPM8J', 'metadata' => ['model' => 'VG34']],
            ['device_type' => 'camera', 'external_device_id' => 'CM-123', 'metadata' => []],
        ], $assets[0]['devices']);

        $this->assertSame([
            ['device_type' => 'gateway', 'external_device_id' => 'G9WR4JKTJZ', 'metadata' => []],
        ], $assets[1]['devices']);

        // Reported as an empty list, never omitted: the sync must be able to tell
        // "this vehicle has no hardware" apart from "devices were not reported",
        // since only the former may detach an existing device.
        $this->assertSame([], $assets[2]['devices']);
    }

    public function test_validate_webhook_signature_accepts_v1_and_raw_forms(): void
    {
        $adapter = app(SamsaraAdapter::class);
        $payload = '{"event":"test"}';
        $secret = 'whsec';
        $timestamp = (string) now()->getTimestamp();
        $hmac = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertTrue($adapter->validateWebhookSignature($payload, $hmac, $secret, $timestamp));
        $this->assertTrue($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret, $timestamp));
        $this->assertFalse($adapter->validateWebhookSignature($payload, 'deadbeef', $secret, $timestamp));
        $this->assertFalse($adapter->validateWebhookSignature($payload, '', $secret, $timestamp));
    }

    /**
     * Sin X-Samsara-Timestamp no hay frescura que comprobar: aceptar un HMAC
     * sobre el cuerpo solo dejaría reenviar para siempre un cuerpo firmado
     * capturado con sólo quitar la cabecera.
     */
    public function test_a_signature_without_timestamp_is_rejected_even_if_the_body_hmac_matches(): void
    {
        $secret = 'whsec';
        $payload = '{"eventId":"abc","eventType":"AlertIncident"}';
        $bodyOnly = hash_hmac('sha256', $payload, $secret);

        foreach ([null, ''] as $timestamp) {
            $this->assertFalse(app(SamsaraAdapter::class)->validateWebhookSignature($payload, 'v1='.$bodyOnly, $secret, $timestamp));
            $this->assertFalse(app(SamsaraAdapter::class)->validateWebhookSignature($payload, $bodyOnly, $secret, $timestamp));
        }

        $entries = $this->systemLogEntries('webhook.signature.rejected');
        $this->assertCount(4, $entries);

        foreach ($entries as $entry) {
            $this->assertSame('missing_timestamp', $entry['context']['reason']);
            $this->assertSame('plain', $entry['context']['input']['scheme']);
        }

        $this->assertSystemNotLogged('webhook.signature.verified');
        $this->assertStringNotContainsString($bodyOnly, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_validate_webhook_signature_verifies_real_samsara_scheme(): void
    {
        $adapter = app(SamsaraAdapter::class);
        $payload = '{"eventId":"abc","eventType":"AlertIncident"}';
        $secret = 'whsec';
        $timestamp = (string) now()->getTimestampMs();

        // Samsara signs "v1:{timestamp}:{rawBody}" and ships it as "v1=<hmac>".
        $hmac = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertTrue($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret, $timestamp));
        $this->assertTrue($adapter->validateWebhookSignature($payload, $hmac, $secret, $timestamp));

        // A signature computed without the timestamp must not validate the timestamped message.
        $plain = hash_hmac('sha256', $payload, $secret);
        $this->assertFalse($adapter->validateWebhookSignature($payload, 'v1='.$plain, $secret, $timestamp));
    }

    public function test_validate_webhook_signature_decodes_base64_secret_key(): void
    {
        $adapter = app(SamsaraAdapter::class);
        $payload = '{"eventId":"abc","eventType":"AlertIncident"}';
        // Samsara's dashboard Secret Key is Base64; the HMAC key is its decoded bytes.
        $rawKey = random_bytes(16);
        $storedSecret = base64_encode($rawKey);
        // Samsara sends X-Samsara-Timestamp in seconds.
        $timestamp = (string) now()->getTimestamp();

        $hmac = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $rawKey);

        $this->assertTrue(
            $adapter->validateWebhookSignature($payload, 'v1='.$hmac, $storedSecret, $timestamp),
            'A signature computed over the Base64-decoded Secret Key must validate.',
        );

        // A signature computed over the raw (still-encoded) secret must NOT validate
        // against the decoded key — confirming we use the decoded bytes.
        $wrong = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, 'not-the-key');
        $this->assertFalse($adapter->validateWebhookSignature($payload, 'v1='.$wrong, $storedSecret, $timestamp));
    }

    public function test_validate_webhook_signature_rejects_stale_timestamp(): void
    {
        config()->set('services.samsara.webhook_tolerance_seconds', 300);

        $adapter = app(SamsaraAdapter::class);
        $payload = '{"eventId":"abc"}';
        $secret = 'whsec';
        // 10 minutes old (in ms) — outside the 5-minute tolerance.
        $timestamp = (string) (now()->getTimestampMs() - 600_000);
        $hmac = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertFalse($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret, $timestamp));

        // With the replay check disabled the same (otherwise valid) signature passes.
        config()->set('services.samsara.webhook_tolerance_seconds', 0);
        $this->assertTrue($adapter->validateWebhookSignature($payload, 'v1='.$hmac, $secret, $timestamp));
    }

    public function test_signature_log_reports_empty_signature(): void
    {
        app(SamsaraAdapter::class)->validateWebhookSignature('{"a":1}', 'v1=', 'whsec');

        $this->assertSystemLogged('webhook.signature.rejected', fn (array $c) => $c['reason'] === 'empty_signature' && $c['input']['scheme'] === 'plain');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_signature_log_reports_invalid_timestamp(): void
    {
        config()->set('services.samsara.webhook_tolerance_seconds', 300);

        $this->assertFalse(app(SamsaraAdapter::class)->validateWebhookSignature('{"a":1}', 'v1=abcdef', 'whsec', 'abc'));

        $this->assertSystemLogged('webhook.signature.rejected', fn (array $c) => $c['reason'] === 'invalid_timestamp' && $c['input']['scheme'] === 'timestamped');
        $this->assertStringNotContainsString('abc"', json_encode($this->systemLogEntries('webhook.signature.rejected')[0]['context']['input']));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_signature_log_reports_stale_timestamp_with_measured_skew(): void
    {
        config()->set('services.samsara.webhook_tolerance_seconds', 300);
        $this->travelTo(now()->startOfSecond());

        $secret = 'whsec';
        $payload = '{"a":1}';
        $timestamp = (string) (now()->getTimestamp() - 600);
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertFalse(app(SamsaraAdapter::class)->validateWebhookSignature($payload, $signature, $secret, $timestamp));

        $this->assertSystemLogged('webhook.signature.rejected', fn (array $c) => $c['reason'] === 'stale_timestamp'
            && $c['calc']['skew_seconds'] === 600
            && $c['calc']['tolerance_seconds'] === 300
            && $c['calc']['reference'] === 'now'
            && $c['calc']['timestamp_unit'] === 'seconds');
        $this->assertStringNotContainsString($signature, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_signature_log_reports_reference_and_unit_for_stale_ms_timestamp_at_receipt(): void
    {
        config()->set('services.samsara.webhook_tolerance_seconds', 300);

        $receivedAt = now()->subHour();
        $timestamp = (string) (($receivedAt->getTimestamp() - 900) * 1000);

        app(SamsaraAdapter::class)->validateWebhookSignature('{"a":1}', 'v1=abcdef', 'whsec', $timestamp, $receivedAt);

        $this->assertSystemLogged('webhook.signature.rejected', fn (array $c) => $c['reason'] === 'stale_timestamp'
            && $c['calc']['skew_seconds'] === 900
            && $c['calc']['reference'] === 'received_at'
            && $c['calc']['timestamp_unit'] === 'milliseconds');
    }

    public function test_signature_log_reports_hmac_mismatch_with_variants_tried(): void
    {
        $secret = base64_encode(random_bytes(16));
        $payload = '{"a":1}';
        $timestamp = (string) now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, 'other-secret');

        $this->assertFalse(app(SamsaraAdapter::class)->validateWebhookSignature($payload, $signature, $secret, $timestamp));

        $this->assertSystemLogged('webhook.signature.rejected', fn (array $c) => $c['reason'] === 'hmac_mismatch'
            && $c['input']['scheme'] === 'timestamped'
            && $c['calc']['key_variants_tried'] === 2
            && $c['calc']['tolerance_seconds'] === (int) config('services.samsara.webhook_tolerance_seconds', 300));
        $this->assertStringNotContainsString($signature, json_encode($this->systemLogEntries()));
        $this->assertStringNotContainsString($secret, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_signature_log_reports_verified_with_base64_decoded_secret(): void
    {
        config()->set('services.samsara.webhook_tolerance_seconds', 300);
        $this->travelTo(now()->startOfSecond());

        $rawKey = random_bytes(16);
        $secret = base64_encode($rawKey);
        $payload = '{"a":1}';
        $timestamp = (string) (now()->getTimestamp() - 5);
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $rawKey);

        $this->assertTrue(app(SamsaraAdapter::class)->validateWebhookSignature($payload, $signature, $secret, $timestamp));

        $this->assertSystemLogged('webhook.signature.verified', fn (array $c) => $c['input']['scheme'] === 'timestamped'
            && $c['calc']['secret_variant'] === 'base64_decoded'
            && $c['calc']['key_variants_tried'] === 1
            && $c['calc']['skew_seconds'] === 5
            && $c['calc']['tolerance_seconds'] === 300);
        $this->assertStringNotContainsString($signature, json_encode($this->systemLogEntries()));
        $this->assertStringNotContainsString($secret, json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_signature_log_reports_verified_with_raw_secret(): void
    {
        $secret = 'not base64!';
        $payload = '{"a":1}';
        $timestamp = (string) now()->getTimestamp();
        $signature = hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertTrue(app(SamsaraAdapter::class)->validateWebhookSignature($payload, $signature, $secret, $timestamp));

        $this->assertSystemLogged('webhook.signature.verified', fn (array $c) => $c['input']['scheme'] === 'timestamped'
            && $c['calc']['secret_variant'] === 'raw'
            && $c['calc']['key_variants_tried'] === 1);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_signature_log_has_no_skew_when_tolerance_check_is_disabled(): void
    {
        config()->set('services.samsara.webhook_tolerance_seconds', 0);

        $secret = 'whsec';
        $payload = '{"a":1}';
        $timestamp = (string) (now()->getTimestamp() - 9999);
        $signature = 'v1='.hash_hmac('sha256', 'v1:'.$timestamp.':'.$payload, $secret);

        $this->assertTrue(app(SamsaraAdapter::class)->validateWebhookSignature($payload, $signature, $secret, $timestamp));

        $context = $this->assertSystemLogged('webhook.signature.verified', fn (array $c) => $c['calc']['tolerance_seconds'] === 0);
        $this->assertNull($context['calc']['skew_seconds'] ?? null);
    }

    public function test_list_uploaded_media_maps_items_and_normalizes_inputs(): void
    {
        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response([
                'data' => ['media' => [
                    [
                        'input' => 'dashcamForwardFacing',
                        'mediaType' => 'videoHighRes',
                        'triggerReason' => 'panicButton',
                        'startTime' => '2026-06-07T01:29:35Z',
                        'endTime' => '2026-06-07T01:30:35Z',
                        'urlInfo' => ['url' => 'https://media.samsara.com/panic.mp4'],
                        'vehicleId' => '281474993032573',
                        'availableAtTime' => '2026-06-07T01:31:00Z',
                    ],
                    [
                        'input' => 'dashcamInwardFacing',
                        'mediaType' => 'image',
                        'triggerReason' => 'panicButton',
                        'startTime' => '2026-06-07T01:29:40Z',
                        'endTime' => '2026-06-07T01:29:40Z',
                        'vehicleId' => '281474993032573',
                        'availableAtTime' => '2026-06-07T01:31:00Z',
                    ],
                ]],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);

        $items = app(SamsaraAdapter::class)->listUploadedMedia(
            $this->makeIntegration(),
            '281474993032573',
            new \DateTimeImmutable('2026-06-07T01:00:00Z'),
            new \DateTimeImmutable('2026-06-07T02:00:00Z'),
            ['panicButton', 'safetyEvent'],
        )['items'];

        $this->assertCount(2, $items);

        // Uploaded-media inputs come in the forward/inward vocabulary and are
        // normalized to the retrieval names used by the rest of the pipeline.
        $this->assertSame('dashcamRoadFacing', $items[0]['input']);
        $this->assertSame('available', $items[0]['status']);
        $this->assertSame('https://media.samsara.com/panic.mp4', $items[0]['url']);
        $this->assertSame('videoHighRes', $items[0]['media_type']);
        $this->assertSame('panicButton', $items[0]['trigger_reason']);
        $this->assertSame('2026-06-07T01:29:35Z', $items[0]['start_time']);

        // No urlInfo yet → pending, never a downloadable item.
        $this->assertSame('dashcamDriverFacing', $items[1]['input']);
        $this->assertSame('pending', $items[1]['status']);
        $this->assertNull($items[1]['url']);

        // triggerReasons is an exploded form param: one repeated key per value,
        // never comma-joined or bracketed (Samsara rejects both).
        Http::assertSent(function ($request) {
            return $request->method() === 'GET'
                && $request['vehicleIds'] === '281474993032573'
                && str_contains($request->url(), 'triggerReasons=panicButton&triggerReasons=safetyEvent')
                && ! str_contains($request->url(), 'triggerReasons%5B')
                && isset($request['startTime'], $request['endTime']);
        });
    }

    public function test_list_uploaded_media_follows_pagination_cursors(): void
    {
        // Con fotos periódicas en la ventana, un listado de ±30 min de una
        // unidad en ruta trae decenas de archivos: hay que leer todas las páginas.
        $item = fn (string $name): array => [
            'input' => 'dashcamForwardFacing',
            'mediaType' => 'image',
            'triggerReason' => 'periodicStill',
            'startTime' => '2026-06-07T01:30:00Z',
            'urlInfo' => ['url' => "https://media.samsara.com/{$name}.jpg"],
        ];

        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::sequence()
                ->push(['data' => ['media' => [$item('p1')]], 'pagination' => ['endCursor' => 'cur-2', 'hasNextPage' => true]])
                ->push(['data' => ['media' => [$item('p2')]], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]),
        ]);

        $items = app(SamsaraAdapter::class)->listUploadedMedia(
            $this->makeIntegration(),
            'veh-1',
            new \DateTimeImmutable('2026-06-07T01:00:00Z'),
            new \DateTimeImmutable('2026-06-07T02:00:00Z'),
            ['periodicStill'],
        )['items'];

        $this->assertSame(
            ['https://media.samsara.com/p1.jpg', 'https://media.samsara.com/p2.jpg'],
            array_column($items, 'url'),
        );

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'after=cur-2')
            && str_contains($request->url(), 'triggerReasons=periodicStill'));
    }

    public function test_list_uploaded_media_returns_empty_items_on_provider_error(): void
    {
        Http::fake([
            'api.samsara.com/cameras/media?*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $result = app(SamsaraAdapter::class)->listUploadedMedia(
            $this->makeIntegration(),
            'veh-1',
            new \DateTimeImmutable('2026-06-07T01:00:00Z'),
            new \DateTimeImmutable('2026-06-07T02:00:00Z'),
        );

        $this->assertSame(['items' => []], $result);
    }

    public function test_list_uploaded_media_without_token_skips_the_provider(): void
    {
        Http::fake();

        $result = app(SamsaraAdapter::class)->listUploadedMedia(
            $this->makeIntegration(token: null),
            'veh-1',
            new \DateTimeImmutable('2026-06-07T01:00:00Z'),
            new \DateTimeImmutable('2026-06-07T02:00:00Z'),
        );

        $this->assertSame(['items' => []], $result);
        Http::assertNothingSent();
    }

    public function test_manager_routes_samsara_provider_to_samsara_adapter(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['data' => []], 200),
        ]);

        $manager = app(ProviderAdapter::class);
        $this->assertInstanceOf(ProviderAdapterManager::class, $manager);

        $result = $manager->testConnection($this->makeIntegration());

        $this->assertTrue($result['success']);
    }

    public function test_manager_routes_unknown_provider_to_null_adapter(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $provider = IntegrationProvider::factory()->create(['code' => 'unknown-provider']);

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'provider_id' => $provider->id,
            'name' => 'Other',
            'status' => 'pending',
            'auth_type' => 'api_key',
            'credentials_encrypted' => 'x',
        ])->load('provider');

        $result = app(ProviderAdapter::class)->sync($integration, 'full');

        // Null adapter returns empty buckets without hitting any HTTP API.
        $this->assertSame(0, $result['records_processed']);
    }

    public function test_sync_keeps_the_driver_profile_facts_and_the_vehicle_plate_as_metadata(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response([
                'data' => [
                    ['id' => '100', 'name' => 'Truck 1', 'vin' => 'VIN100', 'licensePlate' => 'ABC-123', 'make' => 'Volvo'],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
            'api.samsara.com/fleet/drivers*' => Http::response([
                'data' => [
                    [
                        'id' => '200',
                        'name' => 'Jane Doe',
                        'username' => 'jane',
                        'phone' => '+528112345678',
                        'licenseNumber' => 'LIC-1',
                        'licenseState' => 'NL',
                        'driverActivationStatus' => 'active',
                        'timezone' => 'America/Mexico_City',
                        'staticAssignedVehicle' => ['id' => '100', 'name' => 'Truck 1'],
                        'tags' => [['id' => '1', 'name' => 'Norte'], ['id' => '2']],
                        'notes' => '',
                    ],
                    ['id' => '201', 'name' => 'No Extras'],
                ],
                'pagination' => ['hasNextPage' => false],
            ], 200),
        ]);

        $result = app(SamsaraAdapter::class)->sync($this->makeIntegration(), 'full');

        $this->assertSame('ABC-123', $result['assets'][0]['metadata']['license_plate']);
        $this->assertSame('VIN100', $result['assets'][0]['metadata']['vin']);

        $this->assertSame([
            'license_number' => 'LIC-1',
            'license_state' => 'NL',
            'username' => 'jane',
            'activation_status' => 'active',
            'static_vehicle' => 'Truck 1',
            'timezone' => 'America/Mexico_City',
            'tags' => ['Norte'],
        ], $result['drivers'][0]['metadata']);

        // A bare driver record yields empty metadata, never a bag of nulls.
        $this->assertSame([], $result['drivers'][1]['metadata']);

        // The static driver↔vehicle assignment travels as the vehicle's
        // external id; a driver without one reports null (key present, so
        // the sync knows to close any open assignment).
        $this->assertSame('100', $result['drivers'][0]['static_vehicle_external_id']);
        $this->assertArrayHasKey('static_vehicle_external_id', $result['drivers'][1]);
        $this->assertNull($result['drivers'][1]['static_vehicle_external_id']);
    }

    public function test_sync_lists_the_trailers_with_their_gateway_as_trailer_assets(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['data' => [['id' => '100', 'name' => 'T-420']], 'pagination' => ['hasNextPage' => false]]),
            'api.samsara.com/fleet/trailers*' => Http::response([
                'data' => [
                    ['id' => '900', 'name' => 'P-1043', 'licensePlate' => 'ABC-12', 'installedGateway' => ['serial' => 'AG53-XYZ', 'model' => 'AG53']],
                    ['id' => '901', 'name' => 'UNIDAD 07 JCD'],
                ],
                'pagination' => ['hasNextPage' => false],
            ]),
            'api.samsara.com/fleet/drivers*' => Http::response(['data' => [], 'pagination' => ['hasNextPage' => false]]),
        ]);
        $integration = $this->makeIntegration();

        $result = app(SamsaraAdapter::class)->sync($integration, 'full');

        $this->assertSame(['100', '900', '901'], array_column($result['assets'], 'external_id'));
        $trailer = $result['assets'][1];
        $this->assertSame('trailer', $trailer['asset_type_code']);
        $this->assertSame('P-1043', $trailer['name']);
        $this->assertSame([['device_type' => 'gateway', 'external_device_id' => 'AG53-XYZ', 'metadata' => ['model' => 'AG53']]], $trailer['devices']);
        $this->assertSame(['has_camera' => false, 'license_plate' => 'ABC-12'], $trailer['metadata']);
        $this->assertSame([], $result['assets'][2]['devices']);
        $this->assertSystemLogged('samsara.sync.trailers_listed', fn (array $c) => $c['input'] === ['integration_id' => $integration->id, 'team_id' => $integration->team_id]
            && $c['result'] === ['trailers_count' => 2, 'with_gateway_count' => 1]);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_token_without_the_trailers_scope_keeps_the_vehicle_sync(): void
    {
        Http::fake([
            'api.samsara.com/fleet/vehicles*' => Http::response(['data' => [['id' => '100', 'name' => 'T-420']], 'pagination' => ['hasNextPage' => false]]),
            'api.samsara.com/fleet/trailers*' => Http::response(['message' => 'missing scope'], 403),
            'api.samsara.com/fleet/drivers*' => Http::response(['data' => [['id' => '200', 'name' => 'Jane']], 'pagination' => ['hasNextPage' => false]]),
        ]);
        $integration = $this->makeIntegration();

        $result = app(SamsaraAdapter::class)->sync($integration, 'full');

        $this->assertSame(['100'], array_column($result['assets'], 'external_id'));
        $this->assertCount(1, $result['drivers']);
        $this->assertSystemLogged('samsara.sync.trailers_skipped', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'missing_scope'
            && $c['calc']['http_status'] === 403);
        $this->assertSystemNotLogged('samsara.sync.trailers_listed');
        $this->assertNoSensitiveDataLogged();
    }
}
