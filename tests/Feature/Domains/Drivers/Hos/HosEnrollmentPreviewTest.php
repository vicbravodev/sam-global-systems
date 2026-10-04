<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\DriversServiceProvider;
use App\Domains\Drivers\Jobs\SyncHosClocksJob;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosProviderCache;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class HosEnrollmentPreviewTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function ownerOf(TenantIntegration $integration): User
    {
        /** @var User $owner */
        $owner = Team::query()->findOrFail($integration->team_id)->members()->firstOrFail();

        return $owner;
    }

    private function slugOf(TenantIntegration $integration): string
    {
        return Team::query()->findOrFail($integration->team_id)->slug;
    }

    private function reading(string $driver = '58072405', ?string $vehicle = '281'): HosClockReading
    {
        return new HosClockReading($driver, $vehicle, 'driving', 1500, 12000, 21000, 220000, 0);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function preview(TenantIntegration $integration, array $selection): TestResponse
    {
        return $this->actingAs($this->ownerOf($integration))->postJson(
            route('tenant-config.hos.preview', ['current_team' => $this->slugOf($integration)]),
            $selection + ['tag_ids' => [], 'included_asset_ids' => [], 'excluded_asset_ids' => []],
        );
    }

    private function fakeTags(): void
    {
        Http::fake([
            'api.samsara.com/tags*' => Http::response(['data' => [
                ['id' => '4738197', 'name' => 'USA', 'vehicles' => [], 'drivers' => [['id' => '58072405']]],
            ], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]),
        ]);
    }

    public function test_the_preview_counts_with_the_last_poll_without_reading_the_clocks_again(): void
    {
        $integration = $this->hosIntegration();
        $this->hosDriver($integration);
        app(HosProviderCache::class)->putReadings($integration, [$this->reading(), $this->reading('99', null)]);
        $this->fakeTags();

        $this->preview($integration, ['tag_ids' => ['4738197']])
            ->assertOk()
            ->assertJsonPath('data.trucks', 1)
            ->assertJsonPath('data.drivers', 1)
            ->assertJsonPath('data.skipped.no_vehicle', 1)
            ->assertJsonPath('data.failed', false);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fleet/hos/clocks'));
        $log = $this->assertSystemLogged('hos.preview.computed');
        $this->assertSame('cache', $log['calc']['source']);
        $this->assertSame(1, $log['result']['trucks']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_excluding_the_unit_takes_the_driver_out(): void
    {
        $integration = $this->hosIntegration();
        [, $asset] = $this->hosDriver($integration);
        app(HosProviderCache::class)->putReadings($integration, [$this->reading()]);
        $this->fakeTags();

        $this->preview($integration, ['tag_ids' => ['4738197'], 'excluded_asset_ids' => [$asset->id]])
            ->assertOk()
            ->assertJsonPath('data.trucks', 0)
            ->assertJsonPath('data.drivers', 0)
            ->assertJsonPath('data.skipped.excluded', 1);
    }

    public function test_with_a_cold_cache_it_reads_the_clocks_once_and_keeps_them(): void
    {
        $integration = $this->hosIntegration();
        [, $asset] = $this->hosDriver($integration);
        Http::fake([
            'api.samsara.com/fleet/hos/clocks*' => Http::response(['data' => [[
                'driver' => ['id' => '58072405'],
                'currentVehicle' => ['id' => '281'],
                'currentDutyStatus' => ['hosStatusType' => 'driving'],
                'clocks' => ['break' => ['timeUntilBreakDurationMs' => 1500000]],
            ]], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]),
        ]);

        $this->preview($integration, ['included_asset_ids' => [$asset->id]])->assertOk()->assertJsonPath('data.trucks', 1);
        $this->preview($integration, ['included_asset_ids' => [$asset->id]])->assertOk()->assertJsonPath('data.drivers', 1);

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/fleet/hos/clocks')));
        $this->assertSame('provider', $this->systemLogEntries('hos.preview.computed')[0]['context']['calc']['source']);
    }

    public function test_a_samsara_failure_answers_a_degraded_preview(): void
    {
        $integration = $this->hosIntegration();
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response([], 503)]);

        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', true);

        $this->assertSystemLogged('hos.preview.computed', fn (array $context): bool => ($context['reason'] ?? null) === 'provider_error');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_without_a_samsara_integration_there_is_nothing_to_count(): void
    {
        $owner = User::factory()->create();
        TenantFeature::factory()->create(['team_id' => $owner->currentTeam->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);

        $this->actingAs($owner)
            ->postJson(route('tenant-config.hos.preview', ['current_team' => $owner->currentTeam->slug]), ['tag_ids' => [], 'included_asset_ids' => [], 'excluded_asset_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.hasIntegration', false);

        $this->assertSystemLogged('hos.preview.computed', fn (array $context): bool => ($context['reason'] ?? null) === 'no_integration');
    }

    public function test_without_the_feature_tags_and_preview_answer_403(): void
    {
        $integration = $this->hosIntegration(feature: false);

        $this->preview($integration, [])->assertForbidden();
        $this->actingAs($this->ownerOf($integration))
            ->getJson(route('tenant-config.hos.tags', ['current_team' => $this->slugOf($integration)]))
            ->assertForbidden();
    }

    public function test_tags_come_as_a_tree_and_are_cached_per_tenant(): void
    {
        $integration = $this->hosIntegration();
        Http::fake(['api.samsara.com/tags*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'USA', 'drivers' => [['id' => '7']]],
            ['id' => '2', 'name' => 'TRACTOS USA', 'parentTagId' => '1', 'vehicles' => [['id' => '281']]],
        ], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]])]);
        $url = route('tenant-config.hos.tags', ['current_team' => $this->slugOf($integration)]);
        $owner = $this->ownerOf($integration);

        $this->actingAs($owner)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('data.1.parentName', 'USA')
            ->assertJsonPath('data.1.kind', 'vehicle')
            ->assertJsonPath('meta.failed', false)
            ->assertJsonPath('meta.hasIntegration', true);
        $this->actingAs($owner)->getJson($url)->assertOk();

        Http::assertSentCount(1);
        $this->assertTrue(Cache::has(HosProviderCache::tagsKey($integration->team_id, $integration->id)));
        $this->assertSystemLogged('hos.tags.listed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_preview_never_reads_another_tenant(): void
    {
        $mine = $this->hosIntegration();
        [, $asset] = $this->hosDriver($mine);
        $theirs = $this->hosIntegration();
        // Los ids externos son únicos por proveedor: el otro tenant tiene su
        // propio chofer y tracto, y la lectura del mío los menciona (p. ej.
        // un chofer prestado) sin que deban resolverse a sus registros.
        $this->hosDriver($theirs, '77', '282');
        app(HosProviderCache::class)->putReadings($mine, [$this->reading(), $this->reading('77', '282')]);
        app(HosProviderCache::class)->putReadings($theirs, [$this->reading('77', '282')]);

        $response = $this->assertNoTenantLeak($mine->team_id, fn () => $this->preview($mine, ['included_asset_ids' => [$asset->id]]));

        $response->assertOk()
            ->assertJsonPath('data.trucks', 1)
            ->assertJsonPath('data.drivers', 1)
            ->assertJsonPath('data.skipped.driver_unresolved', 1);

        $foreign = Asset::withoutGlobalScopes()->where('team_id', $theirs->team_id)->firstOrFail();
        $this->preview($mine, ['included_asset_ids' => [$foreign->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['included_asset_ids.0']);
    }

    /** Un chofer en /fleet/hos/clocks, sin relojes (nada que avisar). */
    private static function clocksBody(): array
    {
        return ['data' => [[
            'driver' => ['id' => '58072405'],
            'currentVehicle' => ['id' => '281'],
            'currentDutyStatus' => ['hosStatusType' => 'onDuty'],
        ]], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]];
    }

    private function clocksCalls(): int
    {
        return count(Http::recorded(fn ($request) => str_contains($request->url(), '/fleet/hos/clocks')));
    }

    public function test_an_empty_skipped_map_is_a_json_object(): void
    {
        $integration = $this->hosIntegration();
        app(HosProviderCache::class)->putReadings($integration, []);

        $response = $this->preview($integration, [])->assertOk()->assertJsonPath('data.trucks', 0);

        $this->assertStringContainsString('"skipped":{}', (string) $response->getContent());
    }

    public function test_after_a_samsara_failure_the_next_preview_does_not_call_again(): void
    {
        $integration = $this->hosIntegration();
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response([], 503)]);

        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', true);
        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', true);

        $this->assertSame(1, $this->clocksCalls());
        $this->assertTrue(Cache::has(HosProviderCache::failureKey('clocks', $integration->team_id, $integration->id)));
        $this->assertCount(2, $this->systemLogEntries('hos.preview.computed'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_poll_is_not_held_back_by_a_failed_preview(): void
    {
        $integration = $this->hosIntegration();
        $this->hosDriver($integration);
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::sequence()
            ->push([], 503)
            ->push(self::clocksBody()),
        ]);

        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', true);
        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $this->assertSame(2, $this->clocksCalls());
        $this->assertSystemLogged('hos.poll.completed');
        // La lectura que dejó el sondeo sirve aunque el fallo siga recordado.
        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', false);
        $this->assertSame(2, $this->clocksCalls());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_connection_failure_answers_a_degraded_preview(): void
    {
        $integration = $this->hosIntegration();
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::failedConnection()]);

        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', true);

        $this->assertSystemLogged('hos.preview.computed', fn (array $context): bool => ($context['reason'] ?? null) === 'provider_error');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_tags_of_the_integrations_that_answered_survive_a_later_failure(): void
    {
        $integration = $this->hosIntegration();
        $second = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $integration->team_id, 'provider_id' => $integration->provider_id, 'name' => 'Samsara 2',
            'status' => 'active', 'auth_type' => 'api_key', 'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $second->id, 'key' => 'api_token', 'value_encrypted' => 'sk-test-2']);
        Http::fake(['api.samsara.com/tags*' => Http::sequence()
            ->push(['data' => [['id' => '1', 'name' => 'USA', 'drivers' => [['id' => '7']]]], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]])
            ->push([], 503),
        ]);

        $this->actingAs($this->ownerOf($integration))
            ->getJson(route('tenant-config.hos.tags', ['current_team' => $this->slugOf($integration)]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('meta.failed', true);

        $this->assertSystemLogged('hos.tags.listed', fn (array $context): bool => ($context['reason'] ?? null) === 'provider_error');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_tags_and_preview_are_rate_limited_per_user_and_tenant(): void
    {
        $integration = $this->hosIntegration();
        $this->fakeTags();
        $owner = $this->ownerOf($integration);
        $url = route('tenant-config.hos.tags', ['current_team' => $this->slugOf($integration)]);

        for ($i = 0; $i < DriversServiceProvider::HOS_PREVIEW_PER_MINUTE; $i++) {
            $this->actingAs($owner)->getJson($url)->assertOk();
        }

        $this->actingAs($owner)->getJson($url)->assertTooManyRequests();
        $this->preview($integration, [])->assertTooManyRequests();

        // Otro tenant (y otro usuario) tiene su propio cupo.
        $other = $this->hosIntegration();
        $this->actingAs($this->ownerOf($other))
            ->getJson(route('tenant-config.hos.tags', ['current_team' => $this->slugOf($other)]))
            ->assertOk();
    }

    public function test_an_outsider_cannot_drain_the_tenant_preview_budget(): void
    {
        $integration = $this->hosIntegration();
        app(HosProviderCache::class)->putReadings($integration, []);
        $outsider = $this->ownerOf($this->hosIntegration());
        $url = route('tenant-config.hos.preview', ['current_team' => $this->slugOf($integration)]);
        $body = ['tag_ids' => [], 'included_asset_ids' => [], 'excluded_asset_ids' => []];

        for ($i = 0; $i < DriversServiceProvider::HOS_PREVIEW_PER_MINUTE; $i++) {
            $status = $this->actingAs($outsider)->postJson($url, $body)->status();
            $this->assertContains($status, [403, 404]);
        }

        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', false);
    }

    public function test_a_clocks_failure_does_not_mark_warm_tags_as_failed(): void
    {
        $integration = $this->hosIntegration();
        Cache::put(HosProviderCache::tagsKey($integration->team_id, $integration->id), [
            ['id' => '1', 'name' => 'USA', 'parent_id' => null, 'vehicle_ids' => [], 'driver_ids' => ['7']],
        ], 300);
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response([], 503)]);

        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', true);
        $this->assertTrue(Cache::has(HosProviderCache::failureKey('clocks', $integration->team_id, $integration->id)));

        $this->actingAs($this->ownerOf($integration))
            ->getJson(route('tenant-config.hos.tags', ['current_team' => $this->slugOf($integration)]))
            ->assertOk()
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('meta.failed', false);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/tags'));
        $this->assertSystemLogged('hos.tags.listed');
        $this->assertNoSensitiveDataLogged();
    }
}
