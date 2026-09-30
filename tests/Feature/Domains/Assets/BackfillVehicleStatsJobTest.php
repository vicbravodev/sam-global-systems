<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Jobs\BackfillVehicleStatsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Data\VehicleStatsPage;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class BackfillVehicleStatsJobTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private function integration(): TenantIntegration
    {
        $integration = TenantIntegration::factory()->active()->create([
            'team_id' => Team::factory()->create()->id,
            'provider_id' => IntegrationProvider::factory()->samsara()->create()->id,
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test',
        ]);

        return $integration->load('provider');
    }

    public function test_it_refills_the_gap_page_by_page_and_never_rewinds_the_live_position(): void
    {
        $integration = $this->integration();
        $asset = Asset::factory()->create([
            'team_id' => $integration->team_id,
            'last_location_at' => now()->subSeconds(5),
            'last_latitude' => 1.0,
            'last_longitude' => 1.0,
        ]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $integration->provider_id, 'external_id' => '100']);

        $point = fn (int $minutesAgo) => ['latitude' => 19.4, 'longitude' => -99.1, 'speedMilesPerHour' => 30, 'time' => now()->subMinutes($minutesAgo)->toIso8601ZuluString()];

        Http::fake(['api.samsara.com/fleet/vehicles/stats/history*' => Http::sequence()
            ->push(['data' => [['id' => '100', 'gps' => [$point(90), $point(80)]]], 'pagination' => ['endCursor' => 'h1', 'hasNextPage' => true]])
            ->push(['data' => [['id' => '100', 'gps' => [$point(70)]]], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]])]);

        app()->call([new BackfillVehicleStatsJob($integration, TelematicsFeed::Motion, now()->subHours(2), now()), 'handle']);

        $this->assertSame(3, AssetLocationSnapshot::query()->where('asset_id', $asset->id)->count());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'after=h1'));
        $this->assertEqualsWithDelta(1.0, $asset->fresh()->last_latitude, 0.0001);

        // La línea `telematics.backfill.completed` sale atribuida al tenant.
        $this->assertSame($integration->team_id, Context::get('team_id'));
        $this->assertNotNull(Context::get('trace_id'));

        $context = $this->assertSystemLogged('telematics.backfill.completed', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(['pages' => 2, 'stored' => 3, 'dropped_count_by_reason' => []], $context['result']);
        $this->assertSame(500, $context['calc']['max_pages']);
        $this->assertFalse($context['calc']['max_pages_hit']);
        $this->assertFalse($context['calc']['window_capped']);
        $this->assertSame('telematics', $this->systemLogEntries('telematics.backfill.completed')[0]['channel']);
        $this->assertStringNotContainsString('19.4', (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_page_cap_is_not_reported_as_hit_when_the_last_page_has_no_cursor(): void
    {
        $served = 0;
        $this->mock(ProviderAdapter::class)
            ->shouldReceive('fetchVehicleStatsHistory')
            ->andReturnUsing(function () use (&$served) {
                $served++;

                // `hasNextPage` stays true, but the 500th page carries no
                // cursor: there is nothing left the loop could fetch.
                return new VehicleStatsPage(locations: [], readings: [], endCursor: $served < 500 ? "h{$served}" : null, hasNextPage: true);
            });

        app()->call([new BackfillVehicleStatsJob($this->integration(), TelematicsFeed::Motion, now()->subHour(), now()), 'handle']);

        $this->assertSame(500, $served);
        $context = $this->assertSystemLogged('telematics.backfill.completed', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(500, $context['result']['pages']);
        $this->assertSame(500, $context['calc']['max_pages']);
        $this->assertFalse($context['calc']['max_pages_hit']);
    }

    public function test_the_window_is_capped_at_the_configured_hours(): void
    {
        config(['telematics.backfill_hours' => 24]);
        Http::fake(['api.samsara.com/*' => Http::response(['data' => [], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]])]);

        $until = now()->startOfSecond();

        app()->call([new BackfillVehicleStatsJob($this->integration(), TelematicsFeed::Motion, $until->subDays(10), $until), 'handle']);

        Http::assertSent(fn (Request $request) => $request['startTime'] === $until->subHours(24)->utc()->toIso8601ZuluString());

        $this->assertSystemLogged('telematics.backfill.completed', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc']['window_capped'] === true
            && $c['input']['from'] === $until->subHours(24)->utc()->toIso8601ZuluString());
    }

    public function test_an_empty_window_is_skipped_without_calling_the_provider(): void
    {
        config(['telematics.backfill_hours' => 24]);
        Http::fake();

        $until = now()->startOfSecond();
        $integration = $this->integration();

        app()->call([new BackfillVehicleStatsJob($integration, TelematicsFeed::Motion, $until->addMinute(), $until), 'handle']);

        Http::assertNothingSent();
        $context = $this->assertSystemLogged('telematics.backfill.completed', fn (array $c) => $c['reason'] === 'empty_window');
        $this->assertSame($integration->id, $context['input']['integration_id']);
        $this->assertSame(24, $context['calc']['backfill_hours']);
        $this->assertSame($until->subHours(24)->toIso8601ZuluString(), $context['calc']['floor']);
        $this->assertSame('telematics', $this->systemLogEntries('telematics.backfill.completed')[0]['channel']);
    }

    public function test_a_rate_limit_releases_the_job_and_says_so(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '7.2'])]);

        app()->call([new BackfillVehicleStatsJob($this->integration(), TelematicsFeed::Motion, now()->subHour(), now()), 'handle']);

        $this->assertSystemLogged('telematics.backfill.completed', fn (array $c) => $c['reason'] === 'rate_limited'
            && $c['calc']['release_s'] === 8
            && $c['calc']['retry_after_s'] === 7.2
            && $c['calc']['pages_stored_before'] === 0);
    }

    public function test_an_invalid_token_ends_the_backfill_and_says_so(): void
    {
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'unauthorized'], 401)]);

        app()->call([new BackfillVehicleStatsJob($this->integration(), TelematicsFeed::Motion, now()->subHour(), now()), 'handle']);

        $this->assertSystemLogged('telematics.backfill.completed', fn (array $c) => $c['reason'] === 'unauthorized');
        $this->assertNoSensitiveDataLogged();
    }
}
