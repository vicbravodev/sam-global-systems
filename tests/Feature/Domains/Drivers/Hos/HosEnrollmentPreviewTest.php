<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosProviderCache;
use App\Domains\Integrations\Data\HosClockReading;
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
}
