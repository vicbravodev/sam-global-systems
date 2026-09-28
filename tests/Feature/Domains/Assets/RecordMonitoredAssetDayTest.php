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
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Decisión 2026-09-28: el tracto-día se registra cuando se USA (al encender la
 * vigilancia y en el cierre diario de cada unidad vigilada), una fila por
 * unidad y día LOCAL. Ya no depende de una sola foto a las 00:00 UTC.
 */
class RecordMonitoredAssetDayTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

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
    }

    public function test_an_invalid_backfill_date_is_rejected(): void
    {
        $this->artisan('assets:record-usage-meters', ['--date' => '10/09/2026'])->assertFailed();
    }

    public function test_a_suspended_tenant_accrues_no_asset_days(): void
    {
        Subscription::factory()->suspended()->create(['team_id' => $this->team->id]);
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);

        $this->artisan('assets:record-usage-meters')->assertSuccessful();
        $this->assertFalse(app(RecordMonitoredAssetDay::class)->execute($asset));

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
    }

    public function test_one_tenants_close_never_writes_into_another(): void
    {
        $other = Team::factory()->create();
        Asset::factory()->create(['team_id' => $other->id]);
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);

        $this->assertNoTenantLeak($this->team, fn () => app(RecordMonitoredAssetDay::class)->execute($asset));

        $this->assertSame(1, $this->assetDays($this->team));
        $this->assertSame(0, $this->assetDays($other));
    }
}
