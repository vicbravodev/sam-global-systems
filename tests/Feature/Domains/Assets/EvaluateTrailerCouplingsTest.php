<?php

namespace Tests\Feature\Domains\Assets;

use App\Domains\Assets\Actions\EvaluateTrailerCouplings;
use App\Domains\Assets\Enums\CouplingDecoupleReason;
use App\Domains\Assets\Enums\CouplingSource;
use App\Domains\Assets\Jobs\EvaluateTrailerCouplingsJob;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetCoupling;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class EvaluateTrailerCouplingsTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    /** Metres per degree of latitude. */
    private const M_PER_DEG = 111_320;

    private const LAT = 25.67;

    private const LNG = -100.31;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 18:00:00', 'UTC'));
        $this->team = Team::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tractor(?Team $team = null): Asset
    {
        return Asset::factory()->create(['team_id' => ($team ?? $this->team)->id]);
    }

    private function trailer(?Team $team = null): Asset
    {
        return Asset::factory()->trailer()->create(['team_id' => ($team ?? $this->team)->id]);
    }

    /**
     * GPS points from `$fromAgo` to `$toAgo` seconds ago, every `$every`
     * seconds, driving north at `$kph`. `$behindM` puts the unit that many
     * metres back on the same road; `$lagS` puts it on the same road but
     * that many seconds later (where the leader was `$lagS` ago).
     */
    private function drive(Asset $asset, int $fromAgo, int $toAgo, int $every, float $kph = 60, float $behindM = 0, int $lagS = 0, float $lat = self::LAT, float $lng = self::LNG): void
    {
        $mps = $kph / 3.6;
        $last = null;

        for ($ago = $fromAgo; $ago >= $toAgo; $ago -= $every) {
            $metres = $mps * ($fromAgo - $ago - $lagS) - $behindM;
            $last = ['lat' => $lat + $metres / self::M_PER_DEG, 'at' => now()->subSeconds($ago)];

            AssetLocationSnapshot::factory()->create([
                'asset_id' => $asset->id,
                'latitude' => $last['lat'],
                'longitude' => $lng,
                'speed' => $kph,
                'recorded_at' => $last['at'],
            ]);
        }

        $asset->forceFill([
            'last_latitude' => $last['lat'],
            'last_longitude' => $lng,
            'last_speed_kph' => $kph,
            'last_location_at' => $last['at'],
        ])->save();
    }

    private function park(Asset $asset, int $fromAgo, int $toAgo, int $every, float $lat = self::LAT, float $lng = self::LNG): void
    {
        $this->drive($asset, $fromAgo, $toAgo, $every, kph: 0, lat: $lat, lng: $lng);
    }

    private function couple(Asset $tractor, Asset $trailer): AssetCoupling
    {
        return AssetCoupling::factory()->forTractor($tractor)->create([
            'trailer_asset_id' => $trailer->id,
            'last_confirmed_at' => now()->subMinutes(30),
        ]);
    }

    /**
     * @return array{coupled: int, confirmed: int, decoupled: int, held: int}
     */
    private function evaluate(?Team $team = null): array
    {
        return app(EvaluateTrailerCouplings::class)->execute(($team ?? $this->team)->id);
    }

    public function test_a_trailer_moving_with_a_tractor_is_coupled_to_it(): void
    {
        $tractor = $this->tractor();
        $bystander = $this->tractor();
        $trailer = $this->trailer();
        $this->drive($tractor, 300, 0, 10);
        $this->drive($trailer, 290, 10, 30, behindM: 20);
        $this->park($bystander, 300, 0, 60, lat: self::LAT + 0.001);

        $counts = $this->evaluate();

        $this->assertSame(['coupled' => 1, 'confirmed' => 0, 'decoupled' => 0, 'held' => 0], $counts);
        $coupling = AssetCoupling::query()->sole();
        $this->assertSame($tractor->id, $coupling->tractor_asset_id);
        $this->assertSame($trailer->id, $coupling->trailer_asset_id);
        $this->assertSame($this->team->id, $coupling->team_id);
        $this->assertSame(CouplingSource::CoMovement, $coupling->source);
        $this->assertNull($coupling->decoupled_at);
        // Coupled since the first matching point, not since the run.
        $this->assertTrue($coupling->coupled_at->equalTo(now()->subSeconds(290)));
        $this->assertSame(10, $coupling->evidence_json['compared_points']);
        $this->assertSame(1.0, (float) $coupling->evidence_json['match_ratio']);
        $this->assertSame($coupling->id, $trailer->currentTractorCoupling->id);
        $this->assertSame([$trailer->id], $tractor->currentTrailerCouplings->pluck('trailer_asset_id')->all());

        $this->assertSystemLogged('assets.trailer_coupling.coupled', fn (array $c) => $c['input'] === [
            'team_id' => $this->team->id,
            'coupling_id' => $coupling->id,
            'tractor_asset_id' => $tractor->id,
            'trailer_asset_id' => $trailer->id,
        ] && $c['calc']['matched_points'] === 10);
        $this->assertSystemLogged('assets.trailer_coupling.evaluated', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc']['trailers_count'] === 1
            && $c['calc']['tractors_count'] === 2
            && $c['result']['coupled_count'] === 1);
        $entry = $this->systemLogEntries('assets.trailer_coupling.evaluated')[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('telematics', $entry['channel']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_one_tractor_pulls_a_full_of_three_trailers(): void
    {
        $tractor = $this->tractor();
        $trailers = [$this->trailer(), $this->trailer(), $this->trailer()];
        $this->drive($tractor, 300, 0, 5);

        foreach ($trailers as $i => $trailer) {
            $this->drive($trailer, 295, 5, 15, behindM: 15 + 12 * $i);
        }

        $this->evaluate();

        $this->assertSame(3, AssetCoupling::query()->open()->where('tractor_asset_id', $tractor->id)->count());
    }

    public function test_trailers_parked_around_a_tractor_in_a_yard_are_not_coupled(): void
    {
        $tractor = $this->tractor();
        $this->park($tractor, 300, 0, 30);

        foreach ([0.0001, 0.0002, 0.0003] as $offset) {
            $this->park($this->trailer(), 300, 0, 30, lat: self::LAT + $offset);
        }

        $counts = $this->evaluate();

        $this->assertSame(0, AssetCoupling::query()->count());
        $this->assertSame(['coupled' => 0, 'confirmed' => 0, 'decoupled' => 0, 'held' => 0], $counts);
        $this->assertSame('debug', $this->systemLogEntries('assets.trailer_coupling.evaluated')[0]['level']);
    }

    public function test_driving_the_same_road_minutes_apart_is_not_coupling(): void
    {
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $this->drive($tractor, 540, 0, 10);
        // Where the tractor was two minutes earlier: same road, 2 km behind.
        $this->drive($trailer, 300, 0, 10, lagS: 120);

        $this->evaluate();

        $this->assertSame(0, AssetCoupling::query()->count());
    }

    public function test_only_monitored_vehicles_can_pull_a_trailer(): void
    {
        $unmonitored = Asset::factory()->pendingMonitoring()->create(['team_id' => $this->team->id]);
        $trailer = $this->trailer();
        $this->drive($unmonitored, 300, 0, 10);
        $this->drive($trailer, 290, 10, 30, behindM: 20);

        $this->evaluate();

        $this->assertSame(0, AssetCoupling::query()->count());
    }

    public function test_a_trailer_with_too_few_moving_points_is_not_judged(): void
    {
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $this->drive($tractor, 300, 0, 10);
        $this->drive($trailer, 60, 0, 30, behindM: 20);

        $this->evaluate();

        $this->assertSame(0, AssetCoupling::query()->count());
    }

    public function test_a_coupling_still_moving_together_is_confirmed_not_duplicated(): void
    {
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $coupling = $this->couple($tractor, $trailer);
        $this->drive($tractor, 300, 0, 10);
        $this->drive($trailer, 290, 10, 30, behindM: 20);

        $counts = $this->evaluate();

        $this->assertSame(1, $counts['confirmed']);
        $this->assertSame(1, AssetCoupling::query()->count());
        $this->assertTrue($coupling->fresh()->last_confirmed_at->equalTo(now()));
        $this->assertSystemNotLogged('assets.trailer_coupling.coupled');
    }

    public function test_a_trailer_moving_with_another_tractor_switches(): void
    {
        $old = $this->tractor();
        $new = $this->tractor();
        $trailer = $this->trailer();
        $previous = $this->couple($old, $trailer);
        $this->park($old, 300, 0, 30, lat: self::LAT - 0.05);
        $this->drive($new, 300, 0, 10);
        $this->drive($trailer, 290, 10, 30, behindM: 20);

        $counts = $this->evaluate();

        $this->assertSame(['coupled' => 1, 'confirmed' => 0, 'decoupled' => 1, 'held' => 0], $counts);
        $previous->refresh();
        $this->assertSame(CouplingDecoupleReason::Switched, $previous->decouple_reason);
        $this->assertTrue($previous->decoupled_at->equalTo(now()));
        $this->assertSame($new->id, AssetCoupling::query()->open()->sole()->tractor_asset_id);
        $this->assertSystemLogged('assets.trailer_coupling.decoupled', fn (array $c) => $c['input']['coupling_id'] === $previous->id
            && $c['calc']['decouple_reason'] === 'switched');
    }

    public function test_a_trailer_moving_away_from_its_tractor_diverges(): void
    {
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $coupling = $this->couple($tractor, $trailer);
        $this->park($tractor, 300, 0, 20, lat: self::LAT - 0.03);
        $this->drive($trailer, 290, 10, 30);

        $counts = $this->evaluate();

        $this->assertSame(1, $counts['decoupled']);
        $this->assertSame(CouplingDecoupleReason::Diverged, $coupling->fresh()->decouple_reason);
        $this->assertSame(0, AssetCoupling::query()->open()->count());
    }

    public function test_a_tractor_driving_off_without_its_trailer_leaves_it_behind(): void
    {
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $coupling = $this->couple($tractor, $trailer);
        $this->park($trailer, 300, 0, 60, lat: self::LAT - 0.05);
        $this->drive($tractor, 300, 0, 10);

        $counts = $this->evaluate();

        $this->assertSame(1, $counts['decoupled']);
        $coupling->refresh();
        $this->assertSame(CouplingDecoupleReason::LeftBehind, $coupling->decouple_reason);
        $this->assertSystemLogged('assets.trailer_coupling.decoupled', fn (array $c) => $c['calc']['decouple_reason'] === 'left_behind'
            && $c['calc']['distance_m'] > 2000
            && $c['calc']['left_behind_m'] === 2000);
    }

    public function test_a_coupling_holds_while_both_are_stopped(): void
    {
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $coupling = $this->couple($tractor, $trailer);
        $this->park($tractor, 300, 0, 60);
        $this->park($trailer, 300, 0, 60, lat: self::LAT - 0.0002);

        $counts = $this->evaluate();

        $this->assertSame(1, $counts['held']);
        $this->assertNull($coupling->fresh()->decoupled_at);
    }

    public function test_a_coupling_holds_while_the_tractor_drives_off_with_it_out_of_signal(): void
    {
        // The trailer has no fix in the window but is still coupled: a tractor
        // driving nearby says nothing against it.
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $coupling = $this->couple($tractor, $trailer);
        $trailer->forceFill([
            'last_latitude' => self::LAT,
            'last_longitude' => self::LNG,
            'last_location_at' => now()->subHour(),
        ])->save();
        $this->drive($tractor, 120, 0, 10, kph: 30);

        $this->evaluate();

        $this->assertNull($coupling->fresh()->decoupled_at);
    }

    public function test_without_recent_trailers_it_says_so_and_does_nothing(): void
    {
        $this->trailer();
        $this->drive($this->tractor(), 300, 0, 10);

        $counts = $this->evaluate();

        $this->assertSame(['coupled' => 0, 'confirmed' => 0, 'decoupled' => 0, 'held' => 0], $counts);
        $this->assertSystemLogged('assets.trailer_coupling.evaluated', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_recent_trailers'
            && $c['input'] === ['team_id' => $this->team->id]);
    }

    public function test_a_trailer_is_never_coupled_to_another_tenants_tractor(): void
    {
        $other = Team::factory()->create();
        $foreignTractor = $this->tractor($other);
        $foreignTrailer = $this->trailer($other);
        $foreignCoupling = $this->couple($foreignTractor, $foreignTrailer);
        $trailer = $this->trailer();
        $this->drive($foreignTractor, 300, 0, 10);
        $this->drive($trailer, 290, 10, 30, behindM: 20);
        $this->park($foreignTrailer, 300, 0, 60, lat: self::LAT - 0.05);

        $this->assertNoTenantLeak($this->team, fn () => $this->evaluate());

        $this->assertSame(0, AssetCoupling::query()->withoutGlobalScopes()->where('team_id', $this->team->id)->count());
        $this->assertSame(0, AssetCoupling::query()->withoutGlobalScopes()->where('tractor_asset_id', $foreignTractor->id)->where('trailer_asset_id', $trailer->id)->count());
        // The other tenant's left-behind trailer is not this run's to judge.
        $this->assertNull($foreignCoupling->fresh()->decoupled_at);
    }

    public function test_the_job_runs_the_tenants_evaluation_once_at_a_time_on_the_telematics_queue(): void
    {
        $tractor = $this->tractor();
        $trailer = $this->trailer();
        $this->drive($tractor, 300, 0, 10);
        $this->drive($trailer, 290, 10, 30, behindM: 20);

        $job = new EvaluateTrailerCouplingsJob($this->team->id);
        app()->call([$job, 'handle']);

        $this->assertSame('telematics', $job->queue);
        $this->assertSame("trailer-couplings:{$this->team->id}", $job->uniqueId());
        $this->assertSame(1, $job->tries);
        $this->assertSame(1, AssetCoupling::query()->open()->count());
    }
}
