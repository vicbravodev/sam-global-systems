<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Actions\ProcessHosReadings;
use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ProcessHosReadingsTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private Team $team;

    private Driver $driver;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->driver = Driver::factory()->create(['team_id' => $this->team->id, 'full_name' => 'Juan Pérez Secreto']);
        $this->asset = Asset::factory()->create(['team_id' => $this->team->id]);
    }

    private function enrollment(HosClockReading ...$readings): HosEnrollment
    {
        return new HosEnrollment(
            array_map(fn ($r) => ['reading' => $r, 'driver' => $this->driver, 'asset' => $this->asset], $readings),
            [],
        );
    }

    private function process(HosEnrollment $enrollment, string $at = '2026-10-04 12:00:00'): array
    {
        return app(ProcessHosReadings::class)->execute(
            $this->team->id,
            HosMonitoringConfig::fromArray([], config('hos.defaults')),
            $enrollment,
            CarbonImmutable::parse($at),
        );
    }

    private function reading(?string $status, int $break = 28800): HosClockReading
    {
        return new HosClockReading('58072405', '281', $status, $break, 30000, 40000, 200000, 0);
    }

    public function test_it_stores_state_and_opens_an_episode_once(): void
    {
        $counts = $this->process($this->enrollment($this->reading('driving', break: 1500)));

        $this->assertSame(['monitored' => 1, 'opened' => 1, 'resolved' => 0, 'unenrolled' => 0, 'app_disconnected' => 0], $counts);
        $state = HosDriverState::withoutGlobalScopes()->sole();
        $this->assertSame(HosDutyStatus::Driving, $state->duty_status);
        $this->assertSame(1500, $state->break_remaining_s);
        $this->assertSame($this->asset->id, $state->asset_id);

        $episode = HosEpisode::withoutGlobalScopes()->sole();
        $this->assertSame(HosSituation::BreakDue, $episode->situation);
        $this->assertSame(1500, $episode->snapshot_json['break_remaining_s']);

        // Mismo sondeo repetido: idempotente.
        $again = $this->process($this->enrollment($this->reading('driving', break: 1440)), '2026-10-04 12:01:00');
        $this->assertSame(0, $again['opened']);
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->count());

        $opened = $this->assertSystemLogged('hos.episode.opened');
        $this->assertSame($this->team->id, $opened['input']['team_id']);
        $this->assertSame('break_due', $opened['calc']['situation']);
        $this->assertSame(1800, $opened['calc']['lead_s']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('Secreto', json_encode($this->systemLogEntries()));
    }

    public function test_it_resolves_when_the_clock_resets(): void
    {
        $this->process($this->enrollment($this->reading('driving', break: 1500)));
        $counts = $this->process($this->enrollment($this->reading('offDuty', break: 28800)), '2026-10-04 12:40:00');

        $episode = HosEpisode::withoutGlobalScopes()->where('situation', HosSituation::BreakDue)->sole();
        $this->assertSame(HosEpisodeResolution::Corrected, $episode->resolution);
        $this->assertSame(1, $counts['resolved']);
        // La transición 1500 → 28800 parado abre "fin de pausa".
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->where('situation', HosSituation::RestComplete)->count());
        $this->assertSystemLogged('hos.episode.resolved');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_status_since_only_moves_on_a_status_change(): void
    {
        $this->process($this->enrollment($this->reading('driving')));
        $this->process($this->enrollment($this->reading('driving')), '2026-10-04 12:05:00');
        $this->assertSame('2026-10-04 12:00:00', HosDriverState::withoutGlobalScopes()->sole()->status_since->format('Y-m-d H:i:s'));

        $this->process($this->enrollment($this->reading('offDuty')), '2026-10-04 12:10:00');
        $this->assertSame('2026-10-04 12:10:00', HosDriverState::withoutGlobalScopes()->sole()->status_since->format('Y-m-d H:i:s'));
    }

    public function test_a_disconnected_app_keeps_the_last_clocks_and_logs_once(): void
    {
        $this->process($this->enrollment($this->reading('driving', break: 1500)));
        $counts = $this->process($this->enrollment($this->reading(null, break: 28800)), '2026-10-04 12:01:00');
        $this->process($this->enrollment($this->reading(null, break: 28800)), '2026-10-04 12:02:00');

        $state = HosDriverState::withoutGlobalScopes()->sole();
        $this->assertSame(1500, $state->break_remaining_s);
        $this->assertSame('2026-10-04 12:01:00', $state->app_disconnected_since->format('Y-m-d H:i:s'));
        $this->assertSame(1, $counts['app_disconnected']);
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->count());
        $this->assertCount(1, array_filter($this->systemLogEntries(), fn ($e) => $e['code'] === 'hos.driver.app_disconnected'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_drivers_that_leave_the_set_close_their_episodes_as_unenrolled(): void
    {
        $this->process($this->enrollment($this->reading('driving', break: 1500)));
        $counts = $this->process(new HosEnrollment([], ['no_match' => 1]), '2026-10-04 12:01:00');

        $this->assertSame(1, $counts['unenrolled']);
        $this->assertSame(HosEpisodeResolution::Unenrolled, HosEpisode::withoutGlobalScopes()->sole()->resolution);
    }
}
