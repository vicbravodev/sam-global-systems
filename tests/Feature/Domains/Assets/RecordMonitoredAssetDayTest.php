<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\RecordMonitoredAssetDay;
use App\Domains\Assets\Actions\SetAssetMonitoring;
use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AssetMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: el tracto-día se registra cuando se USA (al encender la
 * vigilancia y en el cierre diario de cada unidad vigilada), una fila por
 * unidad y día LOCAL. Ya no depende de una sola foto a las 00:00 UTC.
 */
class RecordMonitoredAssetDayTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AssetMeterSeeder::class);
        $this->team = User::factory()->create()->currentTeam;
    }

    private function assetDays(Team $team): int
    {
        return (int) UsageEvent::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('usage_meter_id', UsageMeter::query()->where('code', AssetDayPricing::METER_CODE)->value('id'))
            ->sum('quantity');
    }

    public function test_switching_a_unit_on_records_todays_asset_day_immediately(): void
    {
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->team->id]);

        app(SetAssetMonitoring::class)->execute($asset, AssetMonitoringState::Monitored);

        $this->assertSame(1, $this->assetDays($this->team));

        // Apagar y volver a encender el mismo día no cobra otro día.
        app(SetAssetMonitoring::class)->execute($asset->fresh(), AssetMonitoringState::Excluded);
        app(SetAssetMonitoring::class)->execute($asset->fresh(), AssetMonitoringState::Monitored);

        $this->assertSame(1, $this->assetDays($this->team));

        $key = RecordMonitoredAssetDay::eventKey($this->team->id, $asset->id, AssetDayPricing::localDate(now()));
        $this->assertCount(1, $this->systemLogEntries('billing.usage.recorded'));
        $this->assertSystemLogged('billing.usage.recorded', fn (array $c) => $c['input']['meter_code'] === 'monitored_asset_days'
            && $c['input']['event_key'] === $key);

        // Fuera del cierre diario el duplicado sigue en info.
        $duplicate = $this->systemLogEntries('billing.usage.duplicate_ignored');
        $this->assertCount(1, $duplicate);
        $this->assertSame('info', $duplicate[0]['level']);

        // El segundo encendido del día: ya cobrado, en info (no viene del cierre).
        $skipped = $this->systemLogEntries('billing.monitored_day.skipped');
        $this->assertCount(1, $skipped);
        $this->assertSame('info', $skipped[0]['level']);
        $this->assertSame('already_recorded', $skipped[0]['context']['reason']);
        $this->assertSame($key, $skipped[0]['context']['result']['event_key']);
        $this->assertSame(['team_id' => $this->team->id, 'asset_id' => $asset->id, 'local_date' => AssetDayPricing::localDate(now())], $skipped[0]['context']['input']);

        $outcomes = array_values(array_filter(array_map(
            fn (array $e) => $e['context']['calc']['asset_day_outcome'],
            $this->systemLogEntries('assets.monitoring.changed'),
        )));
        $this->assertSame(['recorded', 'already_recorded'], $outcomes);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_unit_switched_on_at_6pm_mexico_is_charged_that_local_day_once(): void
    {
        // 18:30 de México = 00:30 UTC del día siguiente: la foto UTC antigua lo
        // habría cobrado "mañana" o nunca. Ahora se fecha en el día local.
        Carbon::setTestNow(Carbon::parse('2026-10-15 18:30:00', 'America/Mexico_City'));
        $asset = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->team->id]);

        app(SetAssetMonitoring::class)->execute($asset, AssetMonitoringState::Monitored);
        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $event = UsageEvent::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('usage_meter_id', UsageMeter::query()->where('code', AssetDayPricing::METER_CODE)->value('id'))
            ->sole();

        $this->assertSame("monitored_asset_day:{$this->team->id}:{$asset->id}:2026-10-15", $event->event_key);
        $this->assertSame('2026-10-15', $event->occurred_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_the_daily_close_can_backfill_a_missed_day(): void
    {
        Asset::factory()->count(2)->create(['team_id' => $this->team->id]);

        $this->artisan('assets:record-usage-meters', ['--date' => '2026-09-10'])->assertSuccessful();
        $this->artisan('assets:record-usage-meters', ['--date' => '2026-09-10'])->assertSuccessful();

        $this->assertSame(2, $this->assetDays($this->team));
        $this->assertSame(2, UsageEvent::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('event_key', 'like', 'monitored_asset_day:%:2026-09-10')
            ->count());

        $closes = $this->systemLogEntries('billing.daily_close.completed');
        $this->assertCount(2, $closes);
        $this->assertSame('ok', $closes[0]['context']['outcome']);
        $this->assertSame(['local_date' => '2026-09-10', 'date_source' => 'option'], $closes[0]['context']['input']);
        $this->assertSame(2, $closes[0]['context']['result']['asset_days_recorded_count']);
        $this->assertSame(0, $closes[0]['context']['result']['asset_days_already_recorded_count']);
        $this->assertSame(0, $closes[1]['context']['result']['asset_days_recorded_count']);
        $this->assertSame(2, $closes[1]['context']['result']['asset_days_already_recorded_count']);
        $this->assertSame(1, $closes[1]['context']['result']['teams_closed_count']);
        $this->assertSame(0, $closes[1]['context']['result']['teams_failed_count']);

        // Recompute: the totals are the sum of the per-tenant closes.
        $closed = $this->systemLogEntries('billing.daily_close.tenant_closed');
        $this->assertCount(2, $closed);
        $this->assertSame(2, $closed[1]['context']['calc']['already_recorded_count']);

        $skipped = $this->systemLogEntries('billing.monitored_day.skipped');
        $this->assertCount(2, $skipped);
        foreach ($skipped as $entry) {
            $this->assertSame('debug', $entry['level']);
            $this->assertSame('already_recorded', $entry['context']['reason']);
        }
        // The re-run's asset-day duplicates are routine for the daily close too.
        $duplicates = array_values(array_filter(
            $this->systemLogEntries('billing.usage.duplicate_ignored'),
            fn (array $e) => $e['context']['input']['meter_code'] === AssetDayPricing::METER_CODE,
        ));
        $this->assertCount(2, $duplicates);
        foreach ($duplicates as $entry) {
            $this->assertSame('debug', $entry['level']);
            $this->assertSame('event_key_exists', $entry['context']['reason']);
        }
        $this->assertNoSensitiveDataLogged();
    }

    public function test_an_invalid_backfill_date_is_rejected(): void
    {
        $this->artisan('assets:record-usage-meters', ['--date' => '10/09/2026'])->assertFailed();

        $context = $this->assertSystemLogged('billing.daily_close.completed', fn (array $c) => ($c['outcome'] ?? null) === 'failed'
            && $c['reason'] === 'invalid_date');
        $this->assertSame(['date_option_present' => true], $context['input']);
        $this->assertStringNotContainsString('10/09/2026', json_encode($this->systemLogEntries()));
        $this->assertStringNotContainsString('10\\/09\\/2026', json_encode($this->systemLogEntries()));
    }

    public function test_a_suspended_tenant_accrues_no_asset_days(): void
    {
        Subscription::factory()->suspended()->create(['team_id' => $this->team->id]);
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);

        $this->artisan('assets:record-usage-meters')->assertSuccessful();
        $this->assertFalse(app(RecordMonitoredAssetDay::class)->execute($asset));

        $this->assertSame(0, $this->assetDays($this->team));

        $blocked = $this->assertSystemLogged('billing.tenant.blocked', fn (array $c) => ($c['reason'] ?? null) === 'subscription_suspended');
        $this->assertSame(['team_id' => $this->team->id, 'stage' => 'daily_close', 'local_date' => AssetDayPricing::localDate(now())], $blocked['input']);
        $this->assertSystemLogged('billing.daily_close.completed', fn (array $c) => $c['result']['teams_blocked_count'] === 1
            && $c['result']['teams_closed_count'] === 0
            && $c['result']['asset_days_recorded_count'] === 0);
        $this->assertSystemNotLogged('billing.daily_close.tenant_closed');

        $this->assertSystemLogged('billing.monitored_day.skipped', fn (array $c) => ($c['reason'] ?? null) === 'tenant_not_billable'
            && $c['calc']['blocked_reason'] === 'subscription_suspended'
            && $c['input']['asset_id'] === $asset->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_unit_that_is_not_monitored_or_inactive_is_skipped_with_its_reason(): void
    {
        $pending = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->team->id]);
        $inactive = Asset::factory()->inactive()->create(['team_id' => $this->team->id]);

        $action = app(RecordMonitoredAssetDay::class);

        $this->assertSame('not_monitored', $action->outcome($pending));
        $this->assertSame('inactive', $action->outcome($inactive));

        $this->assertSystemLogged('billing.monitored_day.skipped', fn (array $c) => ($c['reason'] ?? null) === 'not_monitored'
            && $c['calc']['monitoring_state'] === 'pending' && $c['input']['asset_id'] === $pending->id);
        $this->assertSystemLogged('billing.monitored_day.skipped', fn (array $c) => ($c['reason'] ?? null) === 'inactive'
            && $c['calc']['asset_status'] === 'inactive' && $c['input']['asset_id'] === $inactive->id);
        $this->assertSame(0, $this->assetDays($this->team));
    }

    public function test_a_day_already_charged_by_the_old_nightly_sample_is_not_charged_again(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);
        $today = AssetDayPricing::localDate(now());

        app(RecordUsageEvent::class)->execute(
            teamId: $this->team->id,
            meterCode: AssetDayPricing::METER_CODE,
            quantity: 1,
            eventKey: "monitored_asset_days:{$this->team->id}:{$today}",
        );

        $this->assertFalse(app(RecordMonitoredAssetDay::class)->execute($asset));
        $this->assertSame(1, $this->assetDays($this->team));

        $this->assertSystemLogged('billing.monitored_day.skipped', fn (array $c) => ($c['reason'] ?? null) === 'legacy_sample_exists'
            && $c['calc']['legacy_event_key'] === "monitored_asset_days:{$this->team->id}:{$today}"
            && $c['input']['asset_id'] === $asset->id);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_tenant_whose_close_fails_is_logged_and_the_close_is_degraded(): void
    {
        Asset::factory()->create(['team_id' => $this->team->id]);
        // Sin el meter del tracto-día, RecordUsageEvent lanza y el tenant falla.
        UsageMeter::query()->where('code', AssetDayPricing::METER_CODE)->delete();

        $this->artisan('assets:record-usage-meters', ['--date' => '2026-09-10'])->run();

        $failed = $this->assertSystemLogged('billing.daily_close.tenant_failed', fn (array $c) => ($c['reason'] ?? null) === 'exception');
        $this->assertSame(['team_id' => $this->team->id, 'local_date' => '2026-09-10'], $failed['input']);
        $this->assertSystemNotLogged('billing.daily_close.tenant_closed');

        $completed = $this->assertSystemLogged('billing.daily_close.completed', fn (array $c) => ($c['outcome'] ?? null) === 'degraded'
            && ($c['reason'] ?? null) === 'tenant_failures');
        $this->assertSame(1, $completed['result']['teams_failed_count']);
        $this->assertSame(0, $completed['result']['teams_closed_count']);
        $this->assertSame(
            $completed['result']['teams_scanned_count'],
            $completed['result']['teams_closed_count'] + $completed['result']['teams_blocked_count'] + $completed['result']['teams_failed_count'],
        );
        $this->assertNoSensitiveDataLogged();
    }

    public function test_one_tenants_close_never_writes_into_another(): void
    {
        $other = Team::factory()->create();
        Asset::factory()->create(['team_id' => $other->id]);
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);

        $this->assertNoTenantLeak($this->team, fn () => app(RecordMonitoredAssetDay::class)->execute($asset));

        $this->assertSame(1, $this->assetDays($this->team));
        $this->assertSame(0, $this->assetDays($other));

        $this->artisan('assets:record-usage-meters')->assertSuccessful();

        $closed = $this->systemLogEntries('billing.daily_close.tenant_closed');
        $this->assertEqualsCanonicalizing([$this->team->id, $other->id], array_map(fn (array $e) => $e['context']['input']['team_id'], $closed));
        foreach ($closed as $entry) {
            // Each tenant's line counts only its own fleet (one unit each).
            $this->assertSame(1, $entry['context']['calc']['assets_monitored_count']);
        }

        $completed = $this->assertSystemLogged('billing.daily_close.completed');
        $this->assertStringNotContainsString('team_id', json_encode($completed));
        $this->assertSame(2, $completed['result']['teams_closed_count']);
        $this->assertSame(2, $completed['result']['teams_scanned_count']);
        // One asset-day already recorded for this team today; the other's is new.
        $this->assertSame(1, $completed['result']['asset_days_recorded_count']);
        $this->assertSame(1, $completed['result']['asset_days_already_recorded_count']);
    }
}
