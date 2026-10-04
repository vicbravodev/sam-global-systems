<?php

namespace Tests\Feature\Domains\Assets;

use App\Contracts\TenantConfig\TenantScheduleResolver;
use App\Domains\Assets\Actions\RaiseAfterHoursMovement;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Enums\GeofenceCategory;
use App\Domains\Context\Models\Geofence;
use App\Domains\Ingestion\Jobs\ProcessRawEventJob;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * Roadmap V2-C2: a unit moving while the tenant's schedule says "closed"
 * raises one internal `after_hours_movement` event per asset per local day.
 * The telematics feed calls this inline for every fresh moving point.
 */
class RaiseAfterHoursMovementTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Sunday 03:00 UTC (Saturday 21:00 in Mexico City) — outside the
        // factory profile's Mon–Fri 08:00–18:00 window either way.
        Carbon::setTestNow(Carbon::parse('2026-06-14 03:00:00', 'UTC'));

        $this->teamId = User::factory()->create()->currentTeam->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeSchedule(): TenantScheduleProfile
    {
        return TenantScheduleProfile::factory()->create([
            'team_id' => $this->teamId,
            'is_active' => true,
        ]);
    }

    /** @var list<array{0: Asset, 1: float, 2: \DateTimeInterface}> */
    private array $moving = [];

    private function makeMovingAsset(float $speed = 40.0, ?\DateTimeInterface $recordedAt = null, array $attributes = []): Asset
    {
        $asset = Asset::factory()->create(array_merge([
            'team_id' => $this->teamId,
            'status' => AssetStatus::Active,
            // Estado de movimiento de la ingesta (MovementCriterion).
            'last_moving_at' => $recordedAt ?? now()->subMinutes(2),
            'stopped_since' => null,
        ], $attributes));

        $this->moving[] = [$asset, $speed, $recordedAt ?? now()->subMinutes(2)];

        return $asset;
    }

    /**
     * Stands in for one feed cycle: every moving point goes through the
     * action with the tenant's resolved schedule.
     */
    private function runJob(): void
    {
        foreach ($this->moving as [$asset, $speed, $recordedAt]) {
            app(RaiseAfterHoursMovement::class)->execute(
                asset: $asset,
                schedule: app(TenantScheduleResolver::class)->resolve($asset->team_id),
                latitude: 19.43,
                longitude: -99.13,
                speedKph: $speed,
                recordedAt: Carbon::instance($recordedAt),
            );
        }
    }

    public function test_moving_asset_outside_operating_hours_raises_an_internal_event(): void
    {
        $this->makeSchedule();
        $asset = $this->makeMovingAsset();

        $this->runJob();

        $rawEvent = RawEvent::withoutGlobalScopes()->sole();

        $this->assertSame('after_hours_movement', $rawEvent->event_type_raw);
        $this->assertSame($asset->id, $rawEvent->payload_json['internal']['asset_id']);
        $this->assertEquals(40.0, $rawEvent->payload_json['speed_kph']);

        Queue::assertPushed(ProcessRawEventJob::class);

        $context = $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => $c['outcome'] === 'ok');
        $calc = $context['calc'];
        $this->assertSame(['team_id' => $this->teamId, 'asset_id' => $asset->id], $context['input']);
        $this->assertSame(['raised' => true, 'raw_event_id' => $rawEvent->id, 'job_requested' => true], $context['result']);
        $this->assertSame(40.0, $calc['speed_kph']);
        $this->assertSame(5.0, $calc['moving_threshold_kph']);
        $this->assertGreaterThanOrEqual($calc['moving_threshold_kph'], $calc['speed_kph']);
        $this->assertTrue($calc['motion_state_moving']);
        $this->assertSame(120, $calc['position_age_s']);
        $this->assertSame(RaiseAfterHoursMovement::FRESHNESS_MINUTES * 60, $calc['freshness_s']);
        $this->assertLessThanOrEqual($calc['freshness_s'], $calc['position_age_s']);
        $this->assertNull($calc['last_alert_age_s']);
        $this->assertSame(12 * 3600, $calc['cooldown_s']);
        $this->assertSame('info', $this->systemLogEntries('assets.after_hours.evaluated')[0]['level']);
        $this->assertSame('telematics', $this->systemLogEntries('assets.after_hours.evaluated')[0]['channel']);

        // Neither the unit's name or plate, nor where it is, nor the schedule's zone.
        $json = (string) json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString(json_encode($asset->name), $json);
        if ($asset->code !== null && $asset->code !== '') {
            $this->assertStringNotContainsString(json_encode($asset->code), $json);
        }
        $this->assertStringNotContainsString('19.43', $json);
        $this->assertStringNotContainsString('-99.13', $json);
        $this->assertStringNotContainsString('Mexico', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_evaluate_and_execute_agree(): void
    {
        $this->makeSchedule();
        $asset = $this->makeMovingAsset();
        $schedule = app(TenantScheduleResolver::class)->resolve($asset->team_id);
        $action = app(RaiseAfterHoursMovement::class);

        $evaluation = $action->evaluate($asset, $schedule, 19.43, -99.13, 40.0, now()->subMinutes(2));
        $this->assertTrue($evaluation['raised']);
        $this->assertSame('raised', $evaluation['branch']);
        $this->assertSame(RawEvent::withoutGlobalScopes()->sole()->id, $evaluation['raw_event_id']);

        $this->assertFalse($action->execute($asset, $schedule, 19.43, -99.13, 40.0, now()->subMinutes(2)));
        $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => ($c['reason'] ?? null) === 'cooldown_active');
    }

    public function test_one_event_per_asset_per_local_day(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset();

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());

        $context = $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => ($c['reason'] ?? null) === 'cooldown_active');
        $this->assertLessThan($context['calc']['cooldown_s'], $context['calc']['last_alert_age_s']);
        $this->assertSame(0, $context['calc']['last_alert_age_s']);
        $entry = collect($this->systemLogEntries('assets.after_hours.evaluated'))->firstWhere('context.reason', 'cooldown_active');
        $this->assertSame('debug', $entry['level']);
    }

    public function test_without_a_schedule_profile_nothing_is_raised(): void
    {
        $this->makeMovingAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_within_operating_hours_nothing_is_raised(): void
    {
        // Wednesday 16:00 in Mexico City — inside Mon–Fri 08:00–18:00.
        Carbon::setTestNow(Carbon::parse('2026-06-10 22:00:00', 'UTC'));

        $this->makeSchedule();
        $this->makeMovingAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());

        $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => ($c['reason'] ?? null) === 'within_operating_hours');
        $this->assertSame('debug', $this->systemLogEntries('assets.after_hours.evaluated')[0]['level']);
    }

    public function test_slow_or_stale_positions_do_not_count_as_movement(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset(speed: 2.0);
        $this->makeMovingAsset(speed: 60.0, recordedAt: now()->subHours(2));

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());

        $slow = $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => ($c['reason'] ?? null) === 'not_moving');
        $this->assertSame(2.0, $slow['calc']['speed_kph']);
        $this->assertFalse($slow['calc']['speed_kph'] >= $slow['calc']['moving_threshold_kph']);

        $stale = $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => ($c['reason'] ?? null) === 'stale_position');
        $this->assertSame(7200, $stale['calc']['position_age_s']);
        $this->assertGreaterThan($stale['calc']['freshness_s'], $stale['calc']['position_age_s']);

        foreach ($this->systemLogEntries('assets.after_hours.evaluated') as $entry) {
            $this->assertSame('debug', $entry['level']);
        }
    }

    public function test_inactive_assets_are_ignored(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset(attributes: ['status' => AssetStatus::Inactive]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());

        $context = $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => ($c['reason'] ?? null) === 'asset_inactive');
        $this->assertSame('inactive', $context['calc']['asset_status']);
        $this->assertSame('info', $this->systemLogEntries('assets.after_hours.evaluated')[0]['level']);
    }

    public function test_schedule_of_another_tenant_does_not_trigger_alerts_here(): void
    {
        // Only the OTHER tenant has a schedule profile; my moving asset stays silent.
        $otherTeamId = User::factory()->create()->currentTeam->id;
        TenantScheduleProfile::factory()->create(['team_id' => $otherTeamId, 'is_active' => true]);

        $this->makeMovingAsset();

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->where('team_id', $this->teamId)->count());
    }

    public function test_a_night_of_driving_that_crosses_local_midnight_alerts_once(): void
    {
        $this->makeSchedule();
        $asset = $this->makeMovingAsset();

        // 23:30 local on Saturday.
        Carbon::setTestNow(Carbon::parse('2026-06-14 05:30:00', 'UTC'));
        $this->moving = [[$asset, 60.0, now()->subMinute()]];
        $this->runJob();

        // Still driving at 00:30 local, a new calendar day.
        Carbon::setTestNow(Carbon::parse('2026-06-14 06:30:00', 'UTC'));
        $this->moving = [[$asset->fresh(), 60.0, now()->subMinute()]];
        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());

        // The next night is a new closed stretch.
        Carbon::setTestNow(Carbon::parse('2026-06-15 05:30:00', 'UTC'));
        $this->moving = [[$asset->fresh(), 60.0, now()->subMinute()]];
        $this->runJob();

        $this->assertSame(2, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_a_phantom_speed_on_a_parked_unit_is_not_after_hours_movement(): void
    {
        $this->makeSchedule();

        // 6.4 km/h en el mismo punto del patio: la ingesta lo mantiene detenido.
        $this->makeMovingAsset(speed: 6.4, attributes: [
            'stopped_since' => now()->subHours(3),
            'last_moving_at' => now()->subHours(3),
        ]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
    }

    /**
     * @return array<string, array{0: GeofenceCategory}>
     */
    public static function safeCategories(): array
    {
        return [
            'base propia' => [GeofenceCategory::Base],
            'sitio de cliente' => [GeofenceCategory::ClientSite],
        ];
    }

    #[DataProvider('safeCategories')]
    public function test_moving_inside_own_base_or_client_site_does_not_alert(GeofenceCategory $category): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset();
        Geofence::factory()->create(['team_id' => $this->teamId, 'category' => $category]);

        $this->runJob();

        $this->assertSame(0, RawEvent::withoutGlobalScopes()->count());
        $this->assertSystemLogged('assets.after_hours.evaluated', fn (array $c) => ($c['reason'] ?? null) === 'inside_safe_geofence'
            && $c['calc']['safe_geofence_category'] === $category->value);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_risk_zone_does_not_suppress_the_alert(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset();
        Geofence::factory()->riskZone()->create(['team_id' => $this->teamId]);

        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
    }

    public function test_another_tenants_base_does_not_suppress_the_alert(): void
    {
        $this->makeSchedule();
        $this->makeMovingAsset();
        Geofence::factory()->create(['team_id' => Team::factory()->create()->id, 'category' => GeofenceCategory::Base]);

        $this->runJob();

        $this->assertSame(1, RawEvent::withoutGlobalScopes()->count());
    }
}
