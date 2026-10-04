<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Jobs\PollHosClocksJob;
use App\Domains\Drivers\Jobs\SyncHosClocksJob;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class SyncHosClocksJobTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private function tenant(bool $feature = true): TenantIntegration
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
            TenantSetting::factory()->create([
                'team_id' => $integration->team_id, 'setting_key' => HosMonitoringConfig::SETTING_KEY,
                'setting_group' => SettingGroup::Compliance, 'value_type' => SettingValueType::Json,
                'value_json' => ['tag_ids' => ['4738197']],
            ]);
        }

        return $integration->load('provider');
    }

    private function link(TenantIntegration $integration, string $driverExternalId, string $vehicleExternalId): array
    {
        $driver = Driver::factory()->create(['team_id' => $integration->team_id]);
        DriverExternalReference::factory()->create(['driver_id' => $driver->id, 'provider_id' => $integration->provider_id, 'external_id' => $driverExternalId, 'external_type' => 'driver']);
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $integration->provider_id, 'external_id' => $vehicleExternalId, 'external_type' => 'vehicle']);

        return [$driver, $asset];
    }

    /** `$thenFail`: la segunda lectura de relojes responde 503 (un segundo Http::fake no reemplaza al primero). */
    private function fakeSamsara(int $breakMs = 1593045, bool $thenFail = false, int $reads = 1): void
    {
        $reading = [
            'data' => [[
                'driver' => ['id' => '58072405', 'name' => 'Chofer Uno'],
                'currentVehicle' => ['id' => '281', 'name' => 'T-0321 USA'],
                'currentDutyStatus' => ['hosStatusType' => 'driving'],
                'violations' => ['shiftDrivingViolationDurationMs' => 0, 'cycleViolationDurationMs' => 0],
                'clocks' => [
                    'break' => ['timeUntilBreakDurationMs' => $breakMs],
                    'drive' => ['driveRemainingDurationMs' => 12393045],
                    'shift' => ['shiftRemainingDurationMs' => 21433967],
                    'cycle' => ['cycleRemainingDurationMs' => 223033967],
                ],
            ]],
            'pagination' => ['endCursor' => '', 'hasNextPage' => false],
        ];
        $clocks = Http::sequence();

        for ($i = 0; $i < $reads; $i++) {
            $clocks->push($reading);
        }

        if ($thenFail) {
            $clocks->push([], 503);
        }

        Http::fake([
            'api.samsara.com/fleet/hos/clocks*' => $clocks,
            'api.samsara.com/tags*' => Http::response([
                'data' => [['id' => '4738197', 'name' => 'USA', 'vehicles' => [], 'drivers' => [['id' => '58072405']]]],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);
    }

    public function test_a_poll_opens_the_break_episode_for_a_tagged_driver(): void
    {
        $integration = $this->tenant();
        [$driver] = $this->link($integration, '58072405', '281');
        $this->fakeSamsara();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $episode = HosEpisode::withoutGlobalScopes()->sole();
        $this->assertSame($driver->id, $episode->driver_id);
        $this->assertSame(HosSituation::BreakDue, $episode->situation);

        $completed = $this->assertSystemLogged('hos.poll.completed');
        $this->assertSame(1, $completed['result']['monitored']);
        $this->assertSame(1, $completed['result']['opened']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('Chofer Uno', json_encode($this->systemLogEntries()));
    }

    public function test_a_provider_failure_discards_the_cycle_without_touching_episodes(): void
    {
        $integration = $this->tenant();
        $this->link($integration, '58072405', '281');
        $this->fakeSamsara(thenFail: true);
        app()->call([new SyncHosClocksJob($integration), 'handle']);
        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->count());
        $failed = $this->assertSystemLogged('hos.poll.failed');
        $this->assertSame('provider_error', $failed['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_rejected_token_is_logged_as_unauthorized(): void
    {
        $integration = $this->tenant();
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'invalid token'], 401)]);

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $this->assertSame('unauthorized', $this->assertSystemLogged('hos.poll.failed')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_feature_turned_off_skips_the_poll(): void
    {
        $integration = $this->tenant(feature: false);
        Http::fake();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        Http::assertNothingSent();
        $this->assertSame('feature_disabled', $this->assertSystemLogged('hos.poll.skipped')['reason']);
    }

    public function test_the_orchestrator_only_dispatches_for_tenants_with_the_feature(): void
    {
        Queue::fake();
        $on = $this->tenant();
        $this->tenant(feature: false);

        app()->call([new PollHosClocksJob, 'handle']);

        Queue::assertPushed(SyncHosClocksJob::class, 1);
        Queue::assertPushed(SyncHosClocksJob::class, fn (SyncHosClocksJob $job) => $job->integration->id === $on->id);
        $dispatched = $this->assertSystemLogged('hos.poll.dispatched');
        $this->assertSame(1, $dispatched['result']['dispatched_count']);
        $this->assertSame(1, $dispatched['result']['feature_off_count']);
    }

    public function test_the_same_samsara_ids_never_leak_into_another_tenant(): void
    {
        $a = $this->tenant();
        $this->link($a, '58072405', '281');
        $b = $this->tenant();
        $this->fakeSamsara(reads: 2);

        $this->assertNoTenantLeak($b->team_id, fn () => app()->call([new SyncHosClocksJob($b), 'handle']));

        $this->assertSame(0, HosEpisode::withoutGlobalScopes()->count());
        $this->assertSame(0, HosDriverState::withoutGlobalScopes()->count());
        $completed = $this->assertSystemLogged('hos.poll.completed');
        $this->assertSame(0, $completed['result']['monitored']);
        $this->assertSame(1, $completed['result']['skipped_driver_unresolved']);

        $this->assertNoTenantLeak($a->team_id, fn () => app()->call([new SyncHosClocksJob($a), 'handle']));

        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->where('team_id', $a->team_id)->count());
        $this->assertSame(0, HosEpisode::withoutGlobalScopes()->where('team_id', $b->team_id)->count());
    }
}
