<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraHosAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test',
        ]);

        return $integration->load('provider');
    }

    /** Fila real de /fleet/hos/clocks (2026-10-04), anonimizada. */
    private function drivingRow(): array
    {
        return [
            'driver' => ['id' => '58072405', 'name' => 'Chofer Uno'],
            'currentVehicle' => ['id' => '281474993186040', 'name' => 'T-0321 USA'],
            'currentDutyStatus' => ['hosStatusType' => 'driving'],
            'violations' => ['shiftDrivingViolationDurationMs' => 0, 'cycleViolationDurationMs' => 0],
            'clocks' => [
                'break' => ['timeUntilBreakDurationMs' => 1593045],
                'drive' => ['driveRemainingDurationMs' => 12393045],
                'shift' => ['shiftRemainingDurationMs' => 21433967],
                'cycle' => ['cycleRemainingDurationMs' => 223033967, 'cycleStartedAtTime' => '2026-10-04T00:13:16.000Z'],
            ],
        ];
    }

    public function test_it_maps_hos_clocks_to_seconds(): void
    {
        Http::fake([
            'api.samsara.com/fleet/hos/clocks*' => Http::response([
                'data' => [
                    $this->drivingRow(),
                    // App desconectada: status vacío, sin vehículo.
                    ['driver' => ['id' => '54293941', 'name' => 'Chofer Dos'], 'currentDutyStatus' => ['hosStatusType' => ''], 'violations' => ['shiftDrivingViolationDurationMs' => 60000, 'cycleViolationDurationMs' => 0]],
                    // Sin driver.id: se descarta.
                    ['currentDutyStatus' => ['hosStatusType' => 'offDuty']],
                ],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);

        $readings = app(ProviderAdapter::class)->fetchHosClocks($this->integration());

        $this->assertCount(2, $readings);
        $this->assertSame('58072405', $readings[0]->externalDriverId);
        $this->assertSame('281474993186040', $readings[0]->externalVehicleId);
        $this->assertSame('driving', $readings[0]->dutyStatus);
        $this->assertSame(1593, $readings[0]->breakRemainingSeconds);
        $this->assertSame(12393, $readings[0]->driveRemainingSeconds);
        $this->assertSame(21433, $readings[0]->shiftRemainingSeconds);
        $this->assertSame(223033, $readings[0]->cycleRemainingSeconds);
        $this->assertSame(0, $readings[0]->violationSeconds);

        $this->assertNull($readings[1]->dutyStatus);
        $this->assertNull($readings[1]->externalVehicleId);
        $this->assertNull($readings[1]->breakRemainingSeconds);
        $this->assertSame(60, $readings[1]->violationSeconds);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'limit=512'));
    }

    public function test_it_follows_pagination(): void
    {
        Http::fakeSequence('api.samsara.com/fleet/hos/clocks*')
            ->push(['data' => [$this->drivingRow()], 'pagination' => ['endCursor' => 'abc', 'hasNextPage' => true]])
            ->push(['data' => [array_replace($this->drivingRow(), ['driver' => ['id' => '2', 'name' => 'x']])], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]);

        $readings = app(ProviderAdapter::class)->fetchHosClocks($this->integration());

        $this->assertSame(['58072405', '2'], array_map(fn ($r) => $r->externalDriverId, $readings));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'after=abc'));
    }

    public function test_a_rejected_token_throws_unauthorized(): void
    {
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response(['message' => 'invalid token'], 401)]);

        $this->expectException(ProviderUnauthorized::class);

        app(ProviderAdapter::class)->fetchHosClocks($this->integration());
    }

    public function test_a_server_error_throws_unavailable(): void
    {
        Http::fake(['api.samsara.com/tags*' => Http::response([], 503)]);

        $this->expectException(ProviderUnavailable::class);

        app(ProviderAdapter::class)->fetchTags($this->integration());
    }

    public function test_a_connection_failure_throws_unavailable(): void
    {
        Http::fake(['api.samsara.com/*' => Http::failedConnection()]);

        try {
            app(ProviderAdapter::class)->fetchHosClocks($this->integration());
            $this->fail('Expected ProviderUnavailable.');
        } catch (ProviderUnavailable $e) {
            $this->assertStringStartsWith('Could not reach Samsara', $e->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }
    }

    public function test_it_maps_tags_with_members_and_parent(): void
    {
        Http::fake([
            'api.samsara.com/tags*' => Http::response([
                'data' => [
                    ['id' => '4738197', 'name' => 'USA', 'vehicles' => [['id' => '281474993186040', 'name' => 'T-0321']], 'drivers' => [['id' => '58072405', 'name' => 'Chofer Uno']]],
                    ['id' => '8142583', 'name' => 'LOCAL HT', 'parentTagId' => '4691922', 'drivers' => [['id' => '9', 'name' => 'x']]],
                ],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);

        $tags = app(ProviderAdapter::class)->fetchTags($this->integration());

        $this->assertSame([
            ['id' => '4738197', 'name' => 'USA', 'parent_id' => null, 'vehicle_ids' => ['281474993186040'], 'driver_ids' => ['58072405']],
            ['id' => '8142583', 'name' => 'LOCAL HT', 'parent_id' => '4691922', 'vehicle_ids' => [], 'driver_ids' => ['9']],
        ], $tags);
    }

    public function test_it_throws_when_listing_arrives_truncated(): void
    {
        Http::fake([
            'api.samsara.com/fleet/hos/clocks*' => Http::response([
                'data' => [$this->drivingRow()],
                'pagination' => ['endCursor' => '', 'hasNextPage' => true],
            ]),
        ]);

        $this->expectException(ProviderUnavailable::class);
        $this->expectExceptionMessage('truncated');

        app(ProviderAdapter::class)->fetchHosClocks($this->integration());
    }
}
